<?php

/**
 * This file is part of the opS3ImageStoragePlugin
 * (c) 2010 Kousuke Ebihara <ebihara@php.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

abstract class opS3ImageBaseTask extends sfDoctrineBaseTask
{
  protected function lock($dir)
  {
    if (!@mkdir($dir))
    {
      throw new RuntimeException('Unable to lock');
    }
  }

  protected function unlock($dir)
  {
    @rmdir($dir);
  }

  protected function getS3Bucket()
  {
    require_once 'Services/Amazon/S3.php';

    $account = sfConfig::get('op_image_storage_s3_account');
    $secret = sfConfig::get('op_image_storage_s3_secret');
    $bucket = sfConfig::get('op_image_storage_s3_bucket');

    $s3 = Services_Amazon_S3::getAccount($account, $secret);
    $bucket = $s3->getBucket($bucket);

    return $bucket;
  }
}
