<?php

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2019 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Utils\Http\Clients;
use s9e\TextFormatter\Utils\Http\Client;
class Curl extends Client
{
	protected static $handle;
	public function get($url, array $options = array())
	{
		$options += array('headers' => array());
		$handle = $this->getHandle();
		\curl_setopt($handle, \CURLOPT_HEADER,     !empty($options['returnHeaders']));
		\curl_setopt($handle, \CURLOPT_HTTPGET,    \true);
		\curl_setopt($handle, \CURLOPT_HTTPHEADER, $options['headers']);
		\curl_setopt($handle, \CURLOPT_URL,        $url);
		return \curl_exec($handle);
	}
	public function post($url, array $options = array(), $body = '')
	{
		$options             += array('headers' => array());
		$options['headers'][] = 'Content-Length: ' . \strlen($body);
		$handle = $this->getHandle();
		\curl_setopt($handle, \CURLOPT_HEADER,     !empty($options['returnHeaders']));
		\curl_setopt($handle, \CURLOPT_HTTPHEADER, $options['headers']);
		\curl_setopt($handle, \CURLOPT_POST,       \true);
		\curl_setopt($handle, \CURLOPT_POSTFIELDS, $body);
		\curl_setopt($handle, \CURLOPT_URL,        $url);
		return \curl_exec($handle);
	}
	protected function getHandle()
	{
		if (!isset(self::$handle))
			self::$handle = $this->getNewHandle();
		\curl_setopt(self::$handle, \CURLOPT_SSL_VERIFYPEER, $this->sslVerifyPeer);
		\curl_setopt(self::$handle, \CURLOPT_TIMEOUT,        $this->timeout);
		return self::$handle;
	}
	protected function getNewHandle()
	{
		$handle = \curl_init();
		\curl_setopt($handle, \CURLOPT_ENCODING,       '');
		\curl_setopt($handle, \CURLOPT_FAILONERROR,    \true);
		\curl_setopt($handle, \CURLOPT_FOLLOWLOCATION, \true);
		\curl_setopt($handle, \CURLOPT_RETURNTRANSFER, \true);
		return $handle;
	}
}