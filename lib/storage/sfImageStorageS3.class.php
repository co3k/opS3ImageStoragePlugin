<?php

/**
 * This file is part of the opS3ImageStoragePlugin
 * (c) 2010 Kousuke Ebihara <ebihara@php.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * sfImageStorageS3
 *
 * @package    opS3ImageStoragePlugin
 * @subpackage storage
 * @author     Kousuke Ebihara <ebihara@php.net>
 */
class sfImageStorageS3 extends sfImageStorageDefault
{
  const S3_SCHEME = 's3://';

  static public function getUrlToImage($filename, $size, $format, $absolute = false)
  {
    if (self::isS3Filename($filename))
    {
      $rawFilename = substr($filename, strlen(self::S3_SCHEME));

      $s3Filename = sfImageStorageS3::generateS3Filename($rawFilename, $format, $size);

      return sfConfig::get('op_image_storage_s3_base_url').$s3Filename;
    }

    return parent::getUrlToImage($filename, $size, $format, $absolute);
  }

  public function deleteBinary()
  {
    $filename = $this->file->getName();
    if (!self::isS3Filename($filename))
    {
      return parent::deleteBinary();
    }

    try
    {
      Doctrine::getTable('S3ImageQueue')->create(array(
        'name' => $this->file->getName(),
      ))->save();
    }
    catch (Exception $e)
    {
    }

    return true;
  }

  public static function isS3Filename($filename)
  {
    return (bool)(0 === strpos($filename, self::S3_SCHEME));
  }

  static public function generateS3Filename($filename, $format, $size = '')
  {
    $result = $filename;

    if ($size)
    {
      $result .= '_'.$size;
    }

    return $result.'.'.$format;
  }
}
