<?php

/**
 * This file is part of the opS3ImageStoragePlugin
 * (c) 2010 Kousuke Ebihara <ebihara@php.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class opS3ImageAddTask extends opS3ImageBaseTask
{
  protected function configure()
  {
    $this->addOptions(array(
      new sfCommandOption('application', null, sfCommandOption::PARAMETER_OPTIONAL, 'The application name', null),
      new sfCommandOption('env', null, sfCommandOption::PARAMETER_REQUIRED, 'The environment', 'prod'),

      new sfCommandOption('limit', null, sfCommandOption::PARAMETER_REQUIRED, 'The limit', 10),
    ));

    $this->namespace        = 'opS3Image';
    $this->name             = 'add';
    $this->briefDescription = 'Add images to S3';
    $this->detailedDescription = <<<EOF
The [opS3Image:add|INFO] task adds images to S3.
Call it with:

  [./symfony opS3Image:add --limit=100|INFO]
EOF;
  }

  protected function execute($arguments = array(), $options = array())
  {
    error_reporting(error_reporting() & ~(E_STRICT | E_DEPRECATED));

    $lockDir = sfConfig::get('sf_cache_dir').DIRECTORY_SEPARATOR.'op_s3_image_add_task.lock';

    $databaseManager = new sfDatabaseManager($this->configuration);

    $lastId = Doctrine::getTable('SnsConfig')->get('op_s3_image_add_last_id', 0);
    $files = $this->fetchFiles($lastId, $options['limit']);

    $this->lock($lockDir);

    $this->logBlock('Begin uploading from id:'.$lastId, 'INFO');

    foreach ($files as $file)
    {
      if (!$file->isImage())
      {
        $lastId = $file->getId();

        continue;
      }

      $filename = $file->getName();
      if (sfImageStorageS3::isS3Filename($filename))
      {
        $lastId = $file->getId();

        continue;
      }

      $result = $this->generateImages($file);
      if (!$result)
      {
        $this->logSection('NOTICE', 'Upload '.$file->getName().' is Failed.', null, 'ERROR');

        break;
      }

      Doctrine::getTable('FileBin')->createQuery()
        ->delete()
        ->where('file_id = ?', $file->getId())
        ->execute();

      sfImageHandler::clearFileCache($filename);

      $lastId = $file->getId();
    }

    Doctrine::getTable('SnsConfig')->set('op_s3_image_add_last_id', $lastId);
    $this->logBlock('Uploadings are completed by id:'.$lastId, 'INFO');

    $this->unlock($lockDir);
  }

  protected function fetchFiles($id, $limit)
  {
    return Doctrine::getTable('File')->createQuery()
      ->where('id > ?', $id)
      ->limit($limit)
      ->execute(array(), Doctrine_Core::HYDRATE_ON_DEMAND);
  }

  protected function generateImages(File $file)
  {
    $formats = sfImageHandler::getAllowedFormat();
    $sizes = array_merge(sfImageHandler::getAllowedSize(), array('raw'));

    $failed = false;

    foreach ($sizes as $size)
    {
      if ('raw' !== $size)
      {
        $s = explode('x', $size);
        $width = $s[0];
        $height = $s[1];
      }
      else
      {
        $width = $height = '';
      }

      foreach ($formats as $format)
      {
        $result = $this->uploadBinaryToS3($file, $format, $width, $height);
        if ($result)
        {
          $this->logSection('upload+', '[id:'.$file->id.'] '.$result);
        }
        else
        {
          $message = sprintf('[id:%d] Failed to store "%s" format: %s, size: %s', $file->getId(), $file->getName(), $format, $size);
          $this->logSection('ERROR', $message, strlen($message), 'ERROR');

          $failed = true;

          break;
        }
      }
    }

    if (!$failed)
    {
      $file->setName(sfImageStorageS3::S3_SCHEME.$file->getName());
      $file->save();

      return true;
    }

    return false;
  }

  protected function uploadBinaryToS3(File $file, $format, $width, $height)
  {
    $handler = new sfImageHandler(array(
      'filename' => $file->getName(),
      'format'   => $format,
      'width'    => $width,
      'height'   => $height,
    ));

    $size = $width.'x'.$height;
    if (!$width || !$height)
    {
      $size = '';
    }

    try
    {
      $rawBinary = $handler->getStorage()->getBinary();
      $handler->getGenerator()->resize($rawBinary, $handler->getStorage()->getFormat());
      $binary = $handler->getGenerator()->getBinary($handler->getStorage()->getFormat(), 100);
    }
    catch (Exception $e)
    {
      $binary = '';
    }

    if (!$binary)
    {
      return false;
    }

    $bucket = $this->getS3Bucket();

    $objectName = sfImageStorageS3::generateS3Filename($file->getName(), $format, $size);
    $object = $bucket->getObject($objectName);
    $object->contentType = $file->type;
    $object->acl = Services_Amazon_S3_AccessControlList::ACL_PUBLIC_READ;
    $object->data = $binary;
    $object->save();

    return $objectName;
  }
}
