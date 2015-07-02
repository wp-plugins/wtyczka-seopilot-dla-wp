<?php
class SeoPilotClient
{
	var $sp_version				= '3.03';
	static $sp_socket_timeout	= 10;

	static $sp_logicPL			= '';
	static $sp_logicBL			= '';
	static $sp_logicInfo		= '';
	static $sp_logicPages		= '';

	static $informer 			= false;
	static $sp_template			= false;
	static $item_delim 			= '';
	static $element_delim		= '';
	private static $domain		= 'www.seopilot.pl';

	var $params = array(
		"__allow_ip__"			=> array('188.127.246.50', '188.127.242.122'),
		"__cache_life_time__"	=> 3600,
		"__cache_reload_time__"	=> 300,
		"__charset__"			=> 'DEFAULT',
		"__remote_addr_key__"	=> 'REMOTE_ADDR',
		"__request_uri_key__"	=> 'REQUEST_URI',
		"__link_open_target__"	=> '',
		"__demo_box__"			=> array(),
		"__demo_box_count__"	=> 3,
		"__def_orientation__"	=> 'h'
	);

	var $sp_request_uri			= '';
	var $sp_links_db_file		= '';
	var $sp_pages_db_file		= '';

	var $sp_page_number			= 0;
	var $sp_test				= false;
	var $sp_links_page			= array();
	var $sp_error				= array();
	
	############## public methods ##############
	/**
	 * Constructor
	 * @param Array $options - options allow: request_uri, charset, is_test
	 * @access public
	 */
	function SeoPilotClient($options=array())
	{
		self::$item_delim = chr(1);
		self::$element_delim = chr(2);

		if(!$this->__reloadDb())
			return;

		$this->__initOptions($options);

		$this->__loadLinks();
		if(!($this->sp_page_number == 0 && !$this->sp_test))
			$this->__loadTemplate();
	}

	/**
	 * get count links for the page
	 * @return int
	 * @access public
	 */
	function getCountLinks()
	{
		return sizeof($this->sp_links_page);
	}

	/**
	 * build links for the page
	 * @access public
	 */
	function build_links($count = false, $orientation=null)
	{
		$logic = self::$sp_logicBL;
		if ( count($this->sp_links_page) == 0
				|| !is_callable($logic)
				|| (!self::$sp_template[$this->__getCurrentOrientation($orientation)] && $this->__raiseError("Template is empty"))
		){
			return $this->__showDecorator();
		}
		if(!$count){
			$links = $this->sp_links_page;
		} else {
			$links = array();
				
			for($i = 0; $i < $count; $i++){
				$links[] = array_shift($this->sp_links_page);
			}
		}
		return $this->__showDecorator($logic($this, self::$sp_template[$this->__getCurrentOrientation($orientation)]['html'], $links), $orientation);
	}

	function __getCurrentOrientation($orientation)
	{
		$curr_orient = $this->params['__def_orientation__'];
		if(!is_null($orientation) && in_array($orientation, array('v', 'h', 's')) && self::$sp_template && isset(self::$sp_template[$orientation])){
			$curr_orient = $orientation;
		}
		return $curr_orient;
	}

	/**
	 * @param Bool $pack
	 * @return string Style
	 * @access public
	 */
	function getStyle($pack=true, $orientation=null)
	{
		$result = '';
		if(self::$sp_template && isset(self::$sp_template[$this->__getCurrentOrientation($orientation)]['style']) && $this->getCountLinks() > 0)
		{
			$result = self::$sp_template[$this->__getCurrentOrientation($orientation)]['style'];
			if($pack)
				$result = '<style type="text/css">'.$result.'</style>';
			unset(self::$sp_template[$this->__getCurrentOrientation($orientation)]['style']);
		}
		return $result;
	}

	############## private methods ##############	
	/**
	 * Initialize input params & default params
	 * @param Array $options
	 */
	function __initOptions($options)
	{
		$this->sp_host = preg_replace('{^https?://(www\.)?(.*?)/}i', '$2', strtolower( $_SERVER['HTTP_HOST'] ));

		if(!isset($_SERVER[$this->params['__request_uri_key__']]) || $_SERVER[$this->params['__request_uri_key__']] == ''){
			$this->params['__request_uri_key__'] = 'REQUEST_URI';
		}

		$request_uri = $_SERVER[$this->params['__request_uri_key__']];
		if (isset($options['request_uri']) && strlen($options['request_uri']) != 0){
			$request_uri = $options['request_uri'];
			$this->params['__request_uri_key__'] = 'MANUAL_URI';
		}
		$this->sp_request_uri = rawurldecode(preg_replace('@^https?://.*?/(.*)@smi', '/$1', $request_uri));

		$logic = self::$sp_logicPages;
		if(is_callable($logic))
			$logic($this);

		if (isset($options['charset']) && strlen($options['charset']) != 0 && $this->params['__charset__'] == 'DEFAULT'){
			$this->params['__charset__'] = $options['charset'];
		}

		if (isset($options['is_test'])) {
			$this->sp_test = $options['is_test'];
		}

		if($this->allowIp())
		{
			if(strpos($this->sp_request_uri, SEOPILOT_USER) !== false) {
				$this->sp_test = true;
			}
		}
	}

	/**
	 * Get DB file from main server and save as local file
	 */
	function __loadDb()
	{
		$links = $this->__fetchRemoteFile(self::$domain, "/files/api.php?m=db&hash=".SEOPILOT_USER."&v=".$this->sp_version);
		$this->__writeLinksToDbFile($links);

		@unlink($this->sp_pages_db_file);
		$this->__writeLinksToDbFile(SEOPILOT_USER."\n", true);
	}

	/**
	 * Actualize DB file
	 * @1. check DB file permission
	 * @2. Load db file if !exists
	 * @3. parse db params
	 * @4. check deprecated DB file 
	 * @5. if deprecated reload.
	 */
	function __reloadDb()
	{
		if (!defined('SEOPILOT_USER')) {
			$this->__raiseError("Constant SEOPILOT_USER is not defined.");
			return false;
		}
		$this->sp_links_db_file = dirname(__FILE__) . '/'.SEOPILOT_USER.'.links.db';
		$this->sp_pages_db_file = dirname(__FILE__) . '/'.SEOPILOT_USER.'.pages.db';

		// @1
		if(is_file($this->sp_links_db_file) && !is_writable($this->sp_links_db_file)){
			$this->__raiseError("Can't write to Link db: permission denied!");
			return false;
		}

		// @2
		if(!file_exists($this->sp_links_db_file))
			$this->__loadDb();

		// @3
		if(is_file($this->sp_links_db_file))
			$this->__parseParams();

		// @4
		if(
			(time() - filemtime($this->sp_links_db_file)) > $this->params['__cache_life_time__']
			||
			((time() -  filemtime($this->sp_links_db_file)) > $this->params['__cache_reload_time__'] && filesize($this->sp_links_db_file) == 0)
		) {
			// @5
			$this->__loadDb();
		}
		@clearstatcache();
		if(filesize($this->sp_links_db_file) == 0)
			return false;

		return true;
	}

	/**
	 * read Template from DB File
	 * @access private
	 */
	function __loadTemplate()
	{
		if(empty($this->readFAT))
			return;

		$ctmpl = $this->readFAT['ctmpl']; unset($this->readFAT['ctmpl']);
		$ctmpl = $this->__readFromDbFile($ctmpl['s'], $ctmpl['l']);
		if(!$ctmpl)
			return;

		$ctmpl = explode(self::$element_delim, $ctmpl);
		$tmpl = array();
		foreach($ctmpl as &$theme)
		{
			$tmp = array();
			list($orient, $key, $tmp['s'], $tmp['style'], $tmp['html']) = explode(":", $theme, 5);
			$tmpl[$orient][$key] = $tmp; unset($tmp);
		} $ctmpl = $tmpl; unset($tmpl);

		if(empty($ctmpl))
			return;

		$ftmpl = $this->readFAT['ftmpl']; unset($this->readFAT['ftmpl']);
		foreach($ctmpl as $o => &$tmpls)
		{
			$tmp = false;
			if($this->sp_page_number == 0){
				if(isset($tmpls[1]))
					$tmp = $tmpls[1];
				else
					$tmp = array_shift($tmpls);
			} else if (sizeof($tmpls) == 1){
				$tmp = array_shift($tmpls);
			} else
			{
				krsort($tmpls);
				foreach ($tmpls as $k => $val)
				{
					if($this->sp_page_number % intval($k) == 0)
					{
						$tmp = $val;
						break;
					}
				}
			}
			if(!$tmp) $tmp = array_shift($tmpls);

			$_ftmpl = $this->__readFromDbFile($ftmpl['s'] + $tmp['s'], $tmp['style'] + $tmp['html']);
			if(!$_ftmpl)
				return;
			if(!self::$sp_template) self::$sp_template = array();

			self::$sp_template[$o] = array();
			self::$sp_template[$o]['style'] = base64_decode(substr($_ftmpl, 0, $tmp['style']));
			self::$sp_template[$o]['html'] = base64_decode(substr($_ftmpl, $tmp['style'], $tmp['html']));
		}
	}

	/**
	 * Get links for current page  from DB file  
	 */
	function __loadLinks()
	{
		@clearstatcache();
		if($this->sp_test)
		{
			foreach ($this->params['__demo_box__'] as &$val)
			{
				$val = str_replace('%host%', $this->sp_host, $val);
			}
			$this->sp_links_page = array_fill(0, $this->params['__demo_box_count__'], $this->params['__demo_box__']);
			$this->sp_page_number = mt_rand(1, 100);
		} else
		{
			list($this->sp_page_number, $this->sp_links_page) = $this->__readLinksFromDb($this->sp_request_uri);
		}

		if (!in_array(strtolower($this->params['__charset__']), array('utf-8', 'default')))
		{
			foreach ($this->sp_links_page as &$row)
			{
				foreach($row as $k => $prop)
				{
					if($k == 'url') continue;
					$tmp = iconv('UTF-8', $this->params['__charset__'].'//TRANSLIT//IGNORE', $row[$k]);
					$row[$k] = $tmp ? $tmp: $row[$k];
				}
			}unset($row);
		}
	}

	function __readFAT()
	{
		$readed = $this->__readFromDbFile(0, 10);
		if(!$readed)
			return;
		$lin = substr($readed, 0, strpos($readed, self::$item_delim));

		$readed = $this->__readFromDbFile(strlen($lin) + 1, $lin);
		if(!$readed)
			return;

		$start = strlen($lin) + 1 + $lin;
		$this->readFAT = array();
		$params = explode(self::$element_delim, $readed);
		foreach($params as $prop)
		{
			$tmp = array();
			list($opt, $vals) = explode(':', $prop, 2);
			list($tmp['s'], $tmp['l']) = array($start, intval($vals));
			$start += intval($vals);
		
			$this->readFAT[$opt] = $tmp;
		}
	}

	function __parseParams()
	{
		$this->__readFAT();
		if(empty($this->readFAT)){
			return;
		}

		$params = $this->readFAT['params']; unset($this->readFAT['params']);
		$params = $this->__readFromDbFile($params['s'], $params['l']);
		if(!$params)
			return;

		$params = explode(self::$item_delim, $params);

		$tmp = array();
		foreach($params as $k => $line)
		{
			list($key, $value) = explode(':', $line, 2);
			if(strpos($value, self::$element_delim) !== false)
			{
				$value = explode(self::$element_delim, $value);
				$tmp2 = array();
				foreach($value as $val)
				{
					if(strpos($val, ':') !== false)
					{
						list($kk, $vv) = explode(':', $val, 2);
						$tmp2[$kk] = $vv;
					} else
					{
						$tmp2[] = $val;
					}
				}
				$value = $tmp2;
			}
			$tmp[$key] = $value;
		}
		$this->params = $tmp;unset($tmp, $params);

		$this->__readMethod('lPL', '$lines', self::$sp_logicPL);
		$this->__readMethod('lBL', '$self, $tpl, $links', self::$sp_logicBL);
		$this->__readMethod('lInfo', '$self', self::$sp_logicInfo);
		$this->__readMethod('lPages', '$self', self::$sp_logicPages);
	}

	function __readMethod($key, $args, &$conteiner)
	{
		$$key = $this->readFAT[$key];unset($this->readFAT[$key]);

		$$key = $this->__readFromDbFile(${$key}['s'], ${$key}['l']);
		if(!$$key)
			return;
		$conteiner = create_function($args, base64_decode($$key));
	}

	function __showDecorator($res='', $orientation = null, $style=true)
	{
		if($style)
		{
			if($this->sp_page_number % 2 == 0)
				$res .= $this->getStyle(true, $orientation);
			else
				$res = $this->getStyle(true, $orientation).$res;
		}

		if($this->sp_test)
			$res .= "<input type=\"hidden\" value=\"". substr(SEOPILOT_USER, 0, rand(5,10)) ."\">";

		if($this->allowIp() && !self::$informer)
		{
			$logic = self::$sp_logicInfo;
			if(is_callable($logic))
				$res .= $logic($this);
			self::$informer = true;
		}
		return $res;
	}

	/**
	 * @param String $uri
	 * @param Array $default 
	 * @return int:number, array:links
	 */
	function __readLinksFromDb($uri, $default = array(0, array()))
	{
		if(empty($this->readFAT))
			return $default;

		$clinks = $this->readFAT['clinks']; unset($this->readFAT['clinks']);
		$clinks = $this->__readFromDbFile($clinks['s'], $clinks['l']);
		if(!$clinks)
			return $default;
		
		$math = array(
			'(?:^|'.self::$item_delim.')(\d+)',
			preg_quote($uri, '%'),
			'(\d+)',
			'(\d+)(?:'.self::$item_delim.'|$)'
		);

		if(!preg_match('%'.implode(self::$element_delim, $math).'%smi', $clinks, $result))
			return $default;

		$number = $result[1];
		$flinks = $this->readFAT['flinks']; unset($this->readFAT['flinks']);
		$line = $this->__readFromDbFile($flinks['s'] + $result[2], $result[3]);

		$logic = self::$sp_logicPL;
		if(!is_callable($logic) || !$line)
			return $default;
		return array($number, $logic($line));
	}


	function __readFromDbFile($start, $len)
	{
		if($len <= 0)
			return false;
		$fp = @fopen($this->sp_links_db_file, 'r');
		if ($fp)
		{
			@flock($fp, LOCK_SH);

			fseek($fp, $start);
			$readed = @fread($fp, $len);

			@flock($fp, LOCK_UN);
			@fclose($fp);
			return $readed;
		}
		$this->__raiseError("Can't get data from the file: " . $this->sp_links_db_file);
		return false;
	}

	/**
	 * @param String $data
	 * @access private
	 */
	function __writeLinksToDbFile($data, $pages = false)
	{
		if(!$pages){
			$file = $this->sp_links_db_file;
			$type = 'wb';
		} else {
			$file = $this->sp_pages_db_file;
			$type = 'a+';
		}
		$fp = @fopen($file, $type);
		if ($fp)
		{
			@flock($fp, LOCK_EX);
			$length = strlen($data);
			@fwrite($fp, $data, $length);
			@flock($fp, LOCK_UN);
			@fclose($fp);

			if (md5(file_get_contents($this->sp_links_db_file)) != md5($data)) {
				$this->__raiseError("Integrity was violated while writing to file: " . $this->sp_links_db_file);
			}
		}
	}

	function __raiseError($e)
	{
		$this->sp_error[] = $e;
		return true;
	}

	function __fetchRemoteFile($host, $path)
	{
		@ini_set('allow_url_fopen', 1);
		@ini_set('default_socket_timeout', self::$sp_socket_timeout);

		if (($data = @file_get_contents('http://' . $host . $path)) !== false)
		{
			return $data;
		}
		if ($ch = @curl_init())
		{
			@curl_setopt($ch, CURLOPT_URL, 'http://' . $host . $path);
			@curl_setopt($ch, CURLOPT_HEADER, false);
			@curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			@curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::$sp_socket_timeout);

			$data = @curl_exec($ch);
			@curl_close($ch);
			if($data !== false){
				return $data;
			}
		}

		$buff = '';
		$fp = @fsockopen($host, 80, $errno, $errstr, self::$sp_socket_timeout);
		if ($fp)
		{
			@fputs($fp, "GET {$path} HTTP/1.0\r\nHost: {$host}\r\n");
			while (!@feof($fp))
			{
				$buff .= @fgets($fp, 128);
			}
			@fclose($fp);

			$page = explode("\r\n\r\n", $buff);
			return $page[1];
		}

		$this->__raiseError("Can't connect to server: " . $host . $path.' ['.$errstr.']');
		return '';
	}

	function allowIp()
	{
		if(in_array($_SERVER[$this->params['__remote_addr_key__']], $this->params['__allow_ip__']))
			return true;

		foreach($_SERVER AS $key => $value)
		{
			if(in_array($value, $this->params['__allow_ip__'])){
				$this->params['__remote_addr_key__'] = $key;
				return true;
			}
		}
		return false;
	}
	
	function onCommand($param)
	{
		if(!$this->allowIp() || !isset($param['method']))
			die('ERROR_ACCESS');

		$result = array();
		switch($param['method'])
		{
			case 'forceUpdateCache':
				@unlink($this->sp_links_db_file);
				if(file_exists($this->sp_links_db_file))
				{
					die('ERROR');
				} else 
				{
					die('OK');
				}
			break;
		}
		die('ERROR');
	}
}

if(realpath($_SERVER['SCRIPT_FILENAME']) == realpath(__FILE__))
{
	$method = $_SERVER['REQUEST_METHOD'];
	if($method == 'GET')
	{
		if(!defined('SEOPILOT_USER'))
		{
			$redirect = array_slice(explode('/', $_SERVER["REDIRECT_URL"]), 1, 1);
			$hash = isset($_SERVER["REDIRECT_URL"]) ? array_pop($redirect) : array_pop(explode('/', dirname(__FILE__)));
			if(preg_match('@[a-z0-9]{32}@', $hash))
				define('SEOPILOT_USER', $hash);
			else 
				die('ERROR_SEOPILOT_USER '.$hash);
		}

		$seo = new SeoPilotClient();
		if(isset($_GET['method']))
		{
			$seo->onCommand($_GET);
		} else
		{
			print $seo->__showDecorator("<h1>Work!</h1>", null, false);
		}
	}
}
?>
