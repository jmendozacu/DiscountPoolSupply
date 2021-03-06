<?php

namespace Potato\ImageOptimization\Model\Manager;

use Potato\ImageOptimization\Api\ImageRepositoryInterface;
use Potato\ImageOptimization\Api\Data\ImageInterface;
use Potato\ImageOptimization\Model\Config;
use Potato\ImageOptimization\Model\Image\Fabric;
use Potato\ImageOptimization\Model\Source\Image\Status as StatusSource;
use Potato\ImageOptimization\Model\Image\Gif as GifImage;
use Potato\ImageOptimization\Model\Image\Jpeg as JpegImage;
use Potato\ImageOptimization\Model\Image\Png as PngImage;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Potato\ImageOptimization\Model\Source\Optimization\Error as ErrorSource;
use Potato\ImageOptimization\Logger\Logger;

/**
 * Class Image
 */
class Image
{
    const DEFAULT_BACKUP_FOLDER_NAME = 'po_image_optimization_original_images';
    const TEMP_FOLDER_NAME = 'po_image_optimization_temp_images';

    /** @var Config  */
    protected $config;

    /** @var Filesystem  */
    protected $filesystem;

    /** @var ImageRepositoryInterface  */
    protected $imageRepository;

    /** @var Fabric  */
    protected $imageFabric;

    /** @var Logger  */
    protected $logger;

    /**
     * Image constructor.
     * @param ImageRepositoryInterface $imageRepository
     * @param Config $config
     * @param Filesystem $filesystem
     * @param Fabric $imageFabric
     * @param Logger $logger
     */
    public function __construct(
        ImageRepositoryInterface $imageRepository,
        Config $config,
        Filesystem $filesystem,
        Fabric $imageFabric,
        Logger $logger
    ) {
        $this->imageRepository = $imageRepository;
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->imageFabric = $imageFabric;
        $this->logger = $logger;
    }
    
    /**
     * @param ImageInterface $image
     * @return $this
     */
    public function optimizeImage(ImageInterface $image)
    {
        $imagePath = $image->getPath();
        $image->setErrorType(null);
        if (!is_readable($image->getPath())) {
            if (!file_exists($image->getPath())) {
                $this->imageRepository->delete($image);
            } else {
                $image
                    ->setStatus(StatusSource::STATUS_ERROR)
                    ->setErrorType(ErrorSource::IS_NOT_READABLE)
                    ->setResult(__("Can't read the file. Please check the file permissions."))
                ;
                $this->imageRepository->save($image);
            }
            return $this;
        }
        $result = $this->backupImage($imagePath);
        if (false === $result) {
            $image
                ->setStatus(StatusSource::STATUS_ERROR)
                ->setErrorType(ErrorSource::BACKUP_CREATION)
                ->setResult(__("Can't create a backup of images. Please check the permissions of files and folders."))
            ;
            $this->imageRepository->save($image);
            return $this;
        }
        $imageType = $this->imageRepository->getImageType($image->getPath());
        $optimizationWorker = $this->imageFabric->getOptimizationWorkerByType($imageType);
        if (null === $optimizationWorker) {
            $image
                ->setStatus(StatusSource::STATUS_ERROR)
                ->setErrorType(ErrorSource::UNSUPPORTED_IMAGE)
                ->setResult(__('Unsupported image type. Only images of PNG, JP(E)G and GIF types are supported.'))
                ->setTime(filemtime($imagePath));
            $this->imageRepository->save($image);
            return $this;
        }
        $originalFileSize = filesize($imagePath);
        if (!$this->createTempFile($imagePath)) {
            $image
                ->setStatus(StatusSource::STATUS_ERROR)
                ->setErrorType(ErrorSource::TEMP_CREATION)
                ->setResult(__("Temp file can't be created. Please check the permissions of files and folders."))
                ->setTime(filemtime($imagePath));
            $this->imageRepository->save($image);
            return $this;
        }
        try {
            $image = $optimizationWorker->optimize($image);
        } catch (\Exception $e) {
            $image
                ->setStatus(StatusSource::STATUS_ERROR)
                ->setResult($e->getMessage())
                ->setTime(filemtime($imagePath));
        }
        clearstatcache($imagePath);
        $optimizedFileSize = filesize($imagePath);
        if (FALSE !== $originalFileSize && FALSE !== $optimizedFileSize
            && $optimizedFileSize > $originalFileSize
        ) {
            $rollbackResult = $this->rollbackTempFile($imagePath);
            if (!$rollbackResult) {
                $tempFile = $this->getTempFilePath($imagePath);
                $image
                    ->setStatus(StatusSource::STATUS_ERROR)
                    ->setErrorType(ErrorSource::TEMP_CREATION)
                    ->setResult(__("Can't restore the temp file.
                    Please check the permissions of file and folders.
                    Then restore file from folder %1 and run image optimization again.", $tempFile))
                    ->setTime(filemtime($imagePath));
                $this->imageRepository->save($image);
                return $this;
            }
            $image->setResult(__("%1 bytes -> %1 bytes", $originalFileSize));
        }
        $this->removeTempFile($imagePath);
        $image->setTime(filemtime($imagePath));
        $this->imageRepository->save($image);
        return $this;
    }

    /**
     * @param ImageInterface $image
     * @return $this
     * @throws \Exception
     */
    public function restoreImage(ImageInterface $image)
    {
        $backupImg = $this->getBackupImagePath($image->getPath());
        $result = false;
        if ($backupImg && is_readable($backupImg)) {
            $content = file_get_contents($backupImg);
            $result = file_put_contents($image->getPath(), $content);
        }
        if (false === $result) {
            throw new \Exception(__("Can't restore the backup. Please check the permissions of file and folders."));
        }

        $image
            ->setStatus(StatusSource::STATUS_SKIPPED)
            ->setResult(__("The image has been restored"))
            ->setTime(filemtime($image->getPath()))
        ;
        $this->imageRepository->save($image);
        return $this;
    }

    /**
     * @param string $image
     * @return string
     */
    public function getBackupImagePath($image)
    {
        $path = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR)->getAbsolutePath();
        $path .= self::DEFAULT_BACKUP_FOLDER_NAME . DIRECTORY_SEPARATOR
            . trim(str_replace(BP, '', $image), DIRECTORY_SEPARATOR);
        return $path;
    }

    /**
     * @param string $image
     * @return boolean|int
     */
    protected function backupImage($image)
    {
        if (!$this->config->isAllowImageBackup()) {
            //if backup is not enabled in system configuration continue optimization
            return true;
        }
        $path = str_replace(BP . DIRECTORY_SEPARATOR, '', $this->getBackupImagePath($image));
        $result = false;
        if (!isset($path)) {
            return $result;
        }

        $rootPath = BP;
        if (is_readable($rootPath . DIRECTORY_SEPARATOR . $path)) {
            //backup exist and readable
            return true;
        }
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $target) {
            $rootPath .= DIRECTORY_SEPARATOR . $target;
            if (file_exists($rootPath)) {
                continue;
            }
            $info = pathinfo($rootPath);
            if (array_key_exists('extension', $info) && $info['extension'] != '' && is_readable($image)) {
                $content = file_get_contents($image);
                $result = file_put_contents($rootPath, $content);
                break;
            }
            mkdir($rootPath, $this->config->getFolderPermission(), true);
        }
        return $result;
    }
    
    /**
     * @param ImageInterface $image
     * @return bool
     */
    public function isOutdated(ImageInterface $image)
    {
        if ($image->getStatus() !== StatusSource::STATUS_OPTIMIZED) {
            return false;
        }
        return filemtime($image->getPath()) > $image->getTime();
    }

    /**
     * @param string $imagePath
     * @return bool
     */
    protected function createTempFile($imagePath)
    {
        $target = $this->getTempFilePath($imagePath);
        return copy($imagePath, $target);
    }

    /**
     * @param string $imagePath
     * @return bool
     */
    protected function rollbackTempFile($imagePath)
    {
        $target = $this->getTempFilePath($imagePath);
        return copy($target, $imagePath);
    }

    /**
     * @param string $imagePath
     * @return bool
     */
    protected function removeTempFile($imagePath)
    {
        $target = $this->getTempFilePath($imagePath);
        return unlink($target);
    }

    /**
     * @param string $imagePath
     * @return string
     */
    protected function getTempFilePath($imagePath)
    {
        $path = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR)->getAbsolutePath()
            . self::TEMP_FOLDER_NAME . DIRECTORY_SEPARATOR;
        if (false === file_exists($path)) {
            mkdir($path, $this->config->getFolderPermission(), true);
        }
        return $path . md5($imagePath) . '.img_temp';
    }

    /**
     * @param ImageInterface[] $imageCollection
     * @return $this
     */
    public function optimizeImageCollection($imageCollection)
    {
        foreach ($imageCollection as $image) {
            try {
                $this->optimizeImage($image);
            } catch (\Exception $e) {
                $image->setStatus(StatusSource::STATUS_ERROR);
                $this->imageRepository->save($image);
                $this->logger->error($e->getMessage());
            }
        }
        return $this;
    }
}