<?php

namespace Quetzal19\FileStorageBundle\Services;

use Quetzal19\FileStorageBundle\Entity\File;
use Quetzal19\FileStorageBundle\Repository\FileRepository;
use Doctrine\ORM\EntityManagerInterface;
use ImagickException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\FileBag;

/**
 * Class FileService
 *
 * @package App\Bundle\Files\Services
 */
class FileService
{
    /**
     * @var FileRepository
     */
    private $fileRepository;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var array
     */
    private $availableFileTypes;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var mixed
     */
    private $storePath;

    /**
     * FileService constructor.
     *
     * @param FileRepository $fileRepository
     * @param Filesystem $filesystem
     * @param ParameterBagInterface $parameterBag
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(
        FileRepository $fileRepository,
        Filesystem $filesystem,
        ParameterBagInterface $parameterBag,
        EntityManagerInterface $entityManager
    )
    {
        $this->fileRepository = $fileRepository;
        $this->filesystem = $filesystem;
        $this->availableFileTypes = $parameterBag->get('allowed_file_types');
        $this->storePath = $parameterBag->get('quetzal19_file_storage.file_store_path');
        $this->entityManager = $entityManager;
    }

    /**
     * @param FileBag $fileBag
     *
     * @return File
     */
    public function saveUploadedFiles(FileBag $fileBag): File
    {
        $fileIterator = $fileBag->getIterator();

        /**@var UploadedFile $file * */
        $file = $fileIterator->current();

        return $this->saveUploadedFile($file);
    }

    /**
     * @param UploadedFile $file
     *
     * @return File
     */
    public function saveUploadedFile(UploadedFile $file): File
    {
        $fileName = $file->getClientOriginalName();

        $fullPath = $this->storeUploadedFile($file, $fileName);

        return $this->add($fileName, $fullPath);
    }

    /**
     * Сохраняет загруженный файл в хранилище на диске
     *
     * @param UploadedFile $file
     * @param string $fileName Имя файла, которое нужно задать при сохранении
     * @param bool $saveToRoot Нужно ли сохранить файл в корень хранилища
     *
     * @return string
     */
    public function storeUploadedFile(UploadedFile $file, string $fileName, bool $saveToRoot = false): string
    {
        $fileType = $file->getClientMimeType();

        if (!in_array($fileType, $this->availableFileTypes)) {
            throw new FileException(sprintf('Файл типа "%s" не может быть загружен', $fileType));
        }

        $fileStoreDir = $saveToRoot
            ? $this->storePath
            : sprintf('%s%s', $this->storePath, $this->getFileHash($file));

        $file->move($fileStoreDir, $fileName);

        return sprintf('/%s/%s', $fileStoreDir, $fileName);
    }

    /**
     * Выполняет ресайз переданного изображения
     * в сет для ретины. В качестве размеров указываются
     * размеры обычного изображения
     *
     * @param File|null $image
     * @param int $width
     * @param int $height
     *
     * @return array|null
     *
     * @throws ImagickException
     */
    public function resizeToSet(?File $image, int $width, int $height): ?array
    {
        if ($image === null) {
            return null;
        }

        return [
            'raw' => $image->getPath(),
            '1x'  => $this->getResizedImagePath($image, $width, $height),
            '2x'  => $this->getResizedImagePath($image, $width * 2, $height * 2),
        ];
    }

    /**
     * @param UploadedFile $file
     *
     * @return string
     */
    private function getFileHash(UploadedFile $file)
    {
        return $this->hash($file->getRealPath());
    }

    /**
     * @param string $fileName
     *
     * @return string
     */
    private function hash(string $fileName): string
    {
        return substr(md5(file_get_contents($fileName)), 0, 3);
    }

    /**
     * @param File $image
     * @param int $width
     * @param int $height
     *
     * @return string
     *
     * @throws ImagickException
     */
    public function getResizedImagePath(File $image, int $width, int $height): string
    {
        $relativeImagePath = trim($image->getPath(), '/');
        $resizedImageFolder = sprintf('%s%sx%s/%s/',
                                      $this->storePath,
                                      $width,
                                      $height,
                                      $this->hash($relativeImagePath)
        );

        $this->filesystem->mkdir($resizedImageFolder);
        $resizedImageName = $resizedImageFolder . $image->getName();

        if (file_exists($resizedImageName)) {
            return '/' . $resizedImageName;
        }

        try {
            $imagick = new \Imagick(realpath($relativeImagePath));
        } catch (ImagickException $e) {
            if ($e->getCode() === 420) {
                // 420 - no decode delegate for this image
                // Если нет подходящего модуля для обработки данного типа файла

                return $image->getPath();
            }

            throw $e;
        }

        $imagick->resizeImage($width, $height, \Imagick::FILTER_BESSEL, 1, 1);

        $imagick->writeImage(realpath($resizedImageFolder) . DIRECTORY_SEPARATOR . $image->getName());

        return '/' . $resizedImageName;
    }

    /**
     * Получает из переданного изображения,
     * которое считается изображением для ретины,
     * сет из ссылок на изображение в разных размерах
     *
     * @param File $image
     *
     * @return array|null
     * @throws ImagickException
     */
    public function resizeToRetinaSet(?File $image): ?array
    {
        if (is_null($image)) {
            return null;
        }

        $imagick = new \Imagick(realpath(trim($image->getPath(), '/')));

        $width = $imagick->getImageWidth() / 2;
        $height = $imagick->getImageHeight() / 2;

        $imagick->destroy();

        $resizedPath = $this->getResizedImagePath($image, $width, $height);

        return [
            'raw' => $image->getPath(),
            '1x'  => $resizedPath,
            '2x'  => $image->getPath(),
        ];
    }

    public function getFileById(int $id): File
    {
        return $this->fileRepository->find($id);
    }

    /**
     * @param UploadedFile $file
     * @param array $mimeTypes
     */
    public function checkFileMimeType(UploadedFile $file, array $mimeTypes = []): void
    {
        $type = $file->getMimeType();

        if (!in_array($type, $mimeTypes ?? $this->availableFileTypes)) {
            throw new FileException(sprintf('Файл типа "%s" не может быть загружен', $type));
        }
    }

    /**
     * @param string $fileName
     * @param string $fullPath
     *
     * @return File
     */
    protected function add(string $fileName, string $fullPath)
    {
        $file = new File();

        $file->setName($fileName);
        $file->setPath($fullPath);

        $this->entityManager->persist($file);
        $this->entityManager->flush();

        return $file;
    }
}