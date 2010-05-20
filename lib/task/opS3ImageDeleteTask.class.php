<?php

/**
 * This file is part of the opS3ImageStoragePlugin
 * (c) 2010 Kousuke Ebihara <ebihara@php.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class opS3ImageDeleteTask extends opS3ImageBaseTask
{
  protected function configure()
  {
    $this->addOptions(array(
      new sfCommandOption('application', null, sfCommandOption::PARAMETER_OPTIONAL, 'The application name', null),
      new sfCommandOption('env', null, sfCommandOption::PARAMETER_REQUIRED, 'The environment', 'prod'),

      new sfCommandOption('limit', null, sfCommandOption::PARAMETER_REQUIRED, 'The limit', 10),
    ));

    $this->namespace        = 'opS3Image';
    $this->name             = 'delete';
    $this->briefDescription = 'Delete images from S3';
    $this->detailedDescription = <<<EOF
The [opS3Image:add|INFO] task delete images from S3.
Call it with:

  [./symfony opS3Image:delete --limit=100|INFO]
EOF;
  }

  protected function execute($arguments = array(), $options = array())
  {
    $this->logBlock('Begin to delete files', 'INFO');

    error_reporting(error_reporting() & ~(E_STRICT | E_DEPRECATED));

    $lockDir = sfConfig::get('sf_cache_dir').DIRECTORY_SEPARATOR.'op_s3_image_delete_task.lock';

    $databaseManager = new sfDatabaseManager($this->configuration);

    $items = Doctrine::getTable('S3ImageQueue')->createQuery()
      ->limit($options['limit'])
      ->execute(array(), Doctrine_Core::HYDRATE_ON_DEMAND);

    $formats = sfImageHandler::getAllowedFormat();
    $sizes = array_merge(sfImageHandler::getAllowedSize(), array('raw'));

    foreach ($items as $item)
    {
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
          $name = $this->removeBinaryToS3(substr($item->getName(), strlen(sfImageStorageS3::S3_SCHEME)), $format, $width, $height);
          $this->logSection('delete-', '[id:'.$item->getId().'] '.$name);
        }
      }

      $item->delete();
    }

    $this->unlock($lockDir);

    $this->logBlock('Deleting files are completed.', 'INFO');
  }

  protected function removeBinaryToS3($name, $format, $width, $height)
  {
    $size = $width.'x'.$height;
    if (!$width || !$height)
    {
      $size = '';
    }

    $bucket = $this->getS3Bucket();

    $objectName = sfImageStorageS3::generateS3Filename($name, $format, $size);
    $object = $bucket->getObject($objectName);
    $object->delete();

    return $objectName;
  }
}
