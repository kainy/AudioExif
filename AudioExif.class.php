<?php
// AudioExif.class.php
// 用PHP进行音频文件头部信息的读取与写入
// 目前只支持 WMA 和 MP3 两种格式, 只支持常用的几个头部信息
//
// 写入信息支持: Title(名称), Artist(艺术家), Copyright(版权), Description (描述)
//               Year(年代),  Genre (流派),   AlbumTitle (专辑标题)
// 其中 mp3 和 wma 略有不同, 具体返回的信息还可能更多, 但只有以上信息可以被写入
// mp3 还支持 Track (曲目编号写入)
// 对于 MP3 文件支持 ID3v1也支持ID3v2, 读取时优先 v2, 写入时总是会写入v1, 必要时写入v2
//
// 用法说明: (由于 wma 使用 Unicode 存取, 故还需要 mb_convert_encoding() 扩展
//           返回数据及写入数据均为 ANSI 编码, 即存什么就显示什么 (中文_GB2312)
//
// require ('AudioExif.class.php');
// $AE = new AudioExif([$charset = 'GBK']);
// $file = '/path/to/test.mp3';
//
// 1. 检查文件是否完整 (only for wma, mp3始终返回 true)
// 
// $AE->CheckSize($file);
//
// 2. 读取信息, 返回值由信息组成的数组, 键名解释参见上方
//
// print_r($AE->GetInfo($file));
//
// 3. 写入信息, 第二参数是一个哈希数组, 键->值, 支持的参见上方的, mp3也支持 Track
//    要求第一参数的文件路径可由本程序写入
// $pa = array('Title' => '新标题', 'AlbumTitle' => '新的专辑名称');
// $AE->SetInfo($file, $pa);
//
// 版本: 0.2
// 作者: hightman
// QQ群: 17708754  (非纯PHP进阶交流群)
// 时间: 2007/01/25
// 其它: 该插件花了不少时间搜集查找 wma及mp3 的文件格式说明文档与网页, 希望对大家有用.
//       其实网上已经有不少类似的程序, 但对 wma 实在太少了, 只能在 win 平台下通过 M$ 的
//       API 来操作, 而 MP3 也很少有可以在 unix/linux 命令行操作的, 所以特意写了这个模块
// hightman.20101010: v0.2更好的支持编码转换, 类对像可以传入 charset 参数, 默认为 gbk.
//                    1) 读取信息则统一返回指定的编码, 以便获得的信息, ID3v1则没有编码直接返回原字符串
//                    2) 写入时id3v2和wma转为ucs-2存储, id3v1均不作转换按iso-8859-1存入.
/* ID3v2 的编码规范: 
$00 – ISO-8859-1 (ASCII).
$01 – UCS-2 in ID3v2.2 and ID3v2.3, UTF-16 encoded Unicode with BOM.
      (FE FF, big-endian, FF FE, little-endian)
$02 – UTF-16BE encoded Unicode without BOM in ID3v2.4 only.
$03 – UTF-8 encoded Unicode in ID3v2.4 only.
*/
//
// 如果发现 bug 或提交 patch, 或加以改进使它更加健壮, 请告诉我. 
// (关于 ID3和Wma的文件格式及结构 在网上应该都可以找到参考资料)
//

if (!extension_loaded('mbstring'))
{
	trigger_error('PHP Extension module `mbstring` is required for AudioExif', E_USER_WARNING);
	return true;
}

// the Main Class
class AudioExif
{
	// public vars
	var $_wma = false;
	var $_mp3 = false;
	var $_cs = 'GBK';

	// Construct
	function AudioExif($cs = '')
	{
		// nothing to do
		if ($cs != '') $this->_cs = $cs;
	}

	// check the filesize
	function CheckSize($file)
	{
		$handler = &$this->_get_handler($file);
		if (!$handler) return false;
		return $handler->check_size($file);
	}

	// get the infomations
	function GetInfo($file)
	{
		$handler = &$this->_get_handler($file);
		if (!$handler) return false;
		return $handler->get_info($file, $this->_cs);
	}

	// write the infomations
	function SetInfo($file, $pa)
	{
		if (!is_writable($file))
		{
			trigger_error('AudioExif: file `' . $file . '` can not been overwritten', E_USER_WARNING);
			return false;
		}
		$handler = &$this->_get_handler($file);
		if (!$handler) return false;
		return $handler->set_info($file, $pa, $this->_cs);
	}

	// private methods
	function &_get_handler($file)
	{
		$ext = strtolower(strrchr($file, '.'));
		$ret = false;
		if ($ext == '.mp3')
		{	// MP3
			$ret = &$this->_mp3;
			if (!$ret) $ret = new _Mp3Exif();
		}
		else if ($ext == '.wma')
		{	// wma
			$ret = &$this->_wma;
			if (!$ret) $ret = new _WmaExif();
		}
		else
		{	// unknown
			trigger_error('AudioExif not supported `' . $ext . '` file.', E_USER_WARNING);
		}
		return $ret;
	}
}

// DBCS => gb2312
function _ae_from_dbcs($str, $cs)
{
	// strip the last "\0\0"
	if (substr($str, -2, 2) == "\0\0") $str = substr($str, 0, -2);
	return mb_convert_encoding($str, $cs, 'UCS-2LE');
}

// gb2312 => DBCS
function _ae_to_dbcs($str, $cs)
{
	$str  = mb_convert_encoding($str, 'UCS-2LE', $cs);
	$str .= "\0\0";
	return $str;
}

// file exif
class _AudioExif
{
	var $fd;
	var $head;
	var $head_off;
	var $head_buf;
	
	// init the file handler
	function _file_init($fpath, $write = false)
	{
		$mode = ($write ? 'rb+' : 'rb');
		$this->fd = @fopen($fpath, $mode);
		if (!$this->fd)
		{
			trigger_error('AudioExif: `' . $fpath . '` can not be opened with mode `' . $mode . '`', E_USER_WARNING);
			return false;
		}
		$this->head = false;
		$this->head_off = 0;
		$this->head_buf = '';
		return true;
	}

	// read buffer from the head_buf & move the off pointer
	function _read_head_buf($len)
	{
		if ($len <= 0) return NULL;
		$buf = substr($this->head_buf, $this->head_off, $len);
		$this->head_off += strlen($buf);
		return $buf;
	}

	// read one short value
	function _read_head_short()
	{
		$ord1 = ord(substr($this->head_buf, $this->head_off, 1));
		$ord2 = ord(substr($this->head_buf, $this->head_off+1, 1));
		$this->head_off += 2;
		return ($ord1 + ($ord2<<8));
	}

	// save the file head
	function _file_save($head, $olen, $nlen = 0)
	{
		if ($nlen == 0) $nlen = strlen($head);
		if ($nlen == $olen)
		{
			// shorter
			flock($this->fd, LOCK_EX);
			fseek($this->fd, 0, SEEK_SET);
			fwrite($this->fd, $head, $nlen);
			flock($this->fd, LOCK_UN);
		}
		else
		{
			// longer, buffer required
			$stat = fstat($this->fd);
			$fsize = $stat['size'];

			// buf required (4096?) 应该不会 nlen - olen > 4096 吧
			$woff = 0;
			$roff = $olen;

			// read first buffer
			flock($this->fd, LOCK_EX);
			fseek($this->fd, $roff, SEEK_SET);
			$buf = fread($this->fd, 4096);

			// seek to start
			fseek($this->fd, $woff, SEEK_SET);
			fwrite($this->fd, $head, $nlen);
			$woff += $nlen;

			// seek to woff & write the data
			do
			{
				$buf2 = $buf;
				$roff += 4096;
				if ($roff < $fsize) 
				{
					fseek($this->fd, $roff, SEEK_SET);
					$buf = fread($this->fd, 4096);					
				}

				// save last buffer
				$len2 = strlen($buf2);
				fseek($this->fd, $woff, SEEK_SET);
				fwrite($this->fd, $buf2, $len2);
				$woff += $len2;
			}
			while ($roff < $fsize);
			ftruncate($this->fd, $woff);
			flock($this->fd, LOCK_UN);
		}
	}

	// close the file
	function _file_deinit()
	{
		if ($this->fd)
		{
			fclose($this->fd);
			$this->fd = false;
		}		
	}
}

// wma class
class _WmaExif extends _AudioExif
{
	var $items1 = array('Title', 'Artist', 'Copyright', 'Description', 'Reserved');
	var $items2 = array('Year', 'Genre', 'AlbumTitle');

	// check file size (length) maybe invalid file
	function check_size($file)
	{
		$ret = false;
		if (!$this->_file_init($file)) return true;
		if ($this->_init_header())
		{
			$buf = fread($this->fd, 24);
			$tmp = unpack('H32id/Vlen/H8unused', $buf);	
			if ($tmp['id'] == '3626b2758e66cf11a6d900aa0062ce6c')
			{
				$stat = fstat($this->fd);
				$ret = ($stat['size'] == ($this->head['len'] + $tmp['len']));
			}
		}
		$this->_file_deinit();
		return $ret;
	}

	// set info (save the infos)
	function set_info($file, $pa, $cs)
	{
		// check the pa
		settype($pa, 'array');
		if (!$this->_file_init($file, true)) return false;
		if (!$this->_init_header())
		{
			$this->_file_deinit();
			return false;
		}
		
		// parse the old header & generate the new header
		$head_body = '';
		$st_found = $ex_found = false;
		$head_num = $this->head['num'];
		while (($tmp = $this->_get_head_frame()) && ($head_num > 0))
		{
			$head_num--;
			if ($tmp['id'] == '3326b2758e66cf11a6d900aa0062ce6c')
			{	// Standard Info
				// 1-4
				$st_found = true;
				$st_body1 = $st_body2 = '';				
				$lenx = unpack('v5', $this->_read_head_buf(10));
				$tmp['len'] -= 34;	// 10 + 24
				for ($i = 0; $i < count($this->items1); $i++)
				{
					$l = $lenx[$i+1];
					$k = $this->items1[$i];
					$tmp['len'] -= $l;

					$data = $this->_read_head_buf($l);
					if (isset($pa[$k])) $data = _ae_to_dbcs($pa[$k], $cs);

					$st_body2 .= $data;
					$st_body1 .= pack('v', strlen($data));
				}
				// left length
				if ($tmp['len'] > 0) $st_body2 .= $this->_read_head_buf($tmp['len']);

				// save to head_body
				$head_body .= pack('H32VH8', $tmp['id'], strlen($st_body1 . $st_body2)+24, $tmp['unused']);
				$head_body .= $st_body1 . $st_body2;		
			}
			else if ($tmp['id'] == '40a4d0d207e3d21197f000a0c95ea850')
			{	// extended info
				$ex_found = true;
				
				$inum = $this->_read_head_short();
				$inum2 = $inum;
				$tmp['len'] -= 26;	// 24 + 2
				$et_body = '';
				while ($tmp['len'] > 0 && $inum > 0)
				{
					// attribute name
					$nlen = $this->_read_head_short();
					$nbuf = $this->_read_head_buf($nlen);

					// the flag & value  length
					$flag = $this->_read_head_short();
					$vlen = $this->_read_head_short();
					$vbuf = $this->_read_head_buf($vlen);

					// set the length
					$tmp['len'] -= (6 + $nlen + $vlen);
					$inum--;

					// save the data?
					$name = _ae_from_dbcs($nbuf, $cs);
					$k = substr($name, 3);
					if (in_array($k, $this->items2) && isset($pa[$k]))
					{
						$vbuf = _ae_to_dbcs($pa[$k], $cs);
						$vlen = strlen($vbuf);
						unset($pa[$k]);
					}
					$et_body .= pack('v', $nlen) . $nbuf . pack('vv', $flag, $vlen) . $vbuf;
				}
				// new tag insert??
				foreach ($this->items2 as $k)
				{
					if (isset($pa[$k]))
					{
						$inum2++;
						$nbuf = _ae_to_dbcs('WM/' . $k, $cs);
						$nlen = strlen($nbuf);
						$vbuf = _ae_to_dbcs($pa[$k], $cs);
						$vlen = strlen($vbuf);
						$et_body .= pack('v', $nlen) . $nbuf . pack('vv', 0, $vlen) . $vbuf;
					}
				}
				// left buf?
				if ($tmp['len'] > 0) $et_body .= $this->_read_head_buf($tmp['len']);

				// save to head_body
				$head_body .= pack('H32VH8v', $tmp['id'], strlen($et_body)+26, $tmp['unused'], $inum2);
				$head_body .= $et_body;		
			}
			else
			{
				// just keep other head frame
				$head_body .= pack('H32VH8', $tmp['id'], $tmp['len'], $tmp['unused']);
				if ($tmp['len'] > 24) $head_body .= $this->_read_head_buf($tmp['len']-24);
			}
		}

		// st not found?
		if (!$st_found)
		{
			$st_body1 = $st_body2 = '';
			foreach ($this->items1 as $k)
			{
				$data = (isset($pa[$k]) ? _ae_to_dbcs($pa[$k], $cs) : "");
				$st_body1 .= pack('v', strlen($data));
				$st_body2 .= $data;
			}
			
			// save to head_body
			$head_body .= pack('H32Va4', '3326b2758e66cf11a6d900aa0062ce6c', strlen($st_body1 . $st_body2)+24, '');
			$head_body .= $st_body1 . $st_body2;
			$this->head['num']++;
		}
		// ex not found?
		if (!$ex_found)
		{
			$inum = 0;
			$et_body = '';
			foreach ($this->items2 as $k)
			{
				$nbuf = _ae_to_dbcs('WM/' . $k);
				$vbuf = (isset($pa[$k]) ? _ae_to_dbcs($pa[$k], $cs) : "");
				$et_body .= pack('v', strlen($nbuf)) . $nbuf . pack('vv', 0, strlen($vbuf)) . $vbuf;
				$inum++;
			}
			$head_body .= pack('H32Va4v', '40a4d0d207e3d21197f000a0c95ea850', strlen($et_body)+26, '', $inum);
			$head_body .= $et_body;
			$this->head['num']++;
		}		

		// after save
		$new_len = strlen($head_body) + 30;
		$old_len = $this->head['len'];
		if ($new_len < $old_len)
		{
			$head_body .= str_repeat("\0", $old_len - $new_len);
			$new_len = $old_len;
		}
		$tmp = $this->head;
		$head_buf = pack('H32VVVH4', $tmp['id'], $new_len, $tmp['len2'], $tmp['num'], $tmp['unused']);
		$head_buf .= $head_body;
		$this->_file_save($head_buf, $old_len, $new_len);

		// close the file & return
		$this->_file_deinit();
		return true;
	}

	// get info
	function get_info($file, $cs)
	{
		$ret = array();
		if (!$this->_file_init($file)) return false;
		if (!$this->_init_header())
		{
			$this->_file_deinit();
			return false;
		}

		// get the data from head_buf
		$head_num = $this->head['num'];	// num of head_frame
		while (($tmp = $this->_get_head_frame()) && $head_num > 0)
		{
			$head_num--;
			if ($tmp['id'] == '3326b2758e66cf11a6d900aa0062ce6c')
			{	// Standard Info
				$lenx = unpack('v*', $this->_read_head_buf(10));
				for ($i = 1; $i <= count($this->items1); $i++)
				{
					$k = $this->items1[$i-1];
					$ret[$k] = _ae_from_dbcs($this->_read_head_buf($lenx[$i]), $cs);
				}
			}
			else if ($tmp['id'] == '40a4d0d207e3d21197f000a0c95ea850')
			{	// Extended Info
				$inum = $this->_read_head_short();
				$tmp['len'] -= 26;
				while ($inum > 0 && $tmp['len'] > 0)
				{
					// attribute name
					$nlen = $this->_read_head_short();
					$nbuf = $this->_read_head_buf($nlen);

					// the flag & value  length
					$flag = $this->_read_head_short();
					$vlen = $this->_read_head_short();
					$vbuf = $this->_read_head_buf($vlen);

					// update the XX
					$tmp['len'] -= (6 + $nlen + $vlen);
					$inum--;

					$name = _ae_from_dbcs($nbuf, $cs);
					$k = substr($name, 3);
					if (in_array($k, $this->items2))
					{	// all is string value (refer to falg for other tags)
						$ret[$k] = _ae_from_dbcs($vbuf, $cs);
					}
				}		
			}
			else
			{	// skip only
				if ($tmp['len'] > 24) $this->head_off += ($tmp['len'] - 24);
			}
		}
		$this->_file_deinit();
		return $ret;
	}

	// get the header?
	function _init_header()
	{
		fseek($this->fd, 0, SEEK_SET);
		$buf = fread($this->fd, 30);
		if (strlen($buf) != 30) return false;
		$tmp = unpack('H32id/Vlen/Vlen2/Vnum/H4unused', $buf);
		if ($tmp['id'] != '3026b2758e66cf11a6d900aa0062ce6c')
			return false;

		$this->head_buf = fread($this->fd, $tmp['len'] - 30);
		$this->head = $tmp;
		return true;
	}

	// _get_head_frame()
	function _get_head_frame()
	{
		$buf = $this->_read_head_buf(24);		
		if (strlen($buf) != 24) return false;
		$tmp = unpack('H32id/Vlen/H8unused', $buf);
		return $tmp;
	}
}

// mp3 class (if not IDv2 then select IDv1)
class _Mp3Exif extends _AudioExif
{
	var $head1;
	var $genres = array('Blues','Classic Rock','Country','Dance','Disco','Funk','Grunge','Hip-Hop','Jazz','Metal','New Age','Oldies','Other','Pop','R&B','Rap','Reggae','Rock','Techno','Industrial','Alternative','Ska','Death Metal','Pranks','Soundtrack','Euro-Techno','Ambient','Trip-Hop','Vocal','Jazz+Funk','Fusion','Trance','Classical','Instrumental','Acid','House','Game','Sound Clip','Gospel','Noise','AlternRock','Bass','Soul','Punk','Space','Meditative','Instrumental Pop','Instrumental Rock','Ethnic','Gothic','Darkwave','Techno-Industrial','Electronic','Pop-Folk','Eurodance','Dream','Southern Rock','Comedy','Cult','Gangsta','Top 40','Christian Rap','Pop/Funk','Jungle','Native American','Cabaret','New Wave','Psychadelic','Rave','Showtunes','Trailer','Lo-Fi','Tribal','Acid Punk','Acid Jazz','Polka','Retro','Musical','Rock & Roll','Hard Rock','Unknown');

	// MP3 always return true
	function check_size($file)
	{
		return true;
	}

	// get info
	function get_info($file, $cs)
	{
		if (!$this->_file_init($file)) return false;		
		$ret = false;
		if ($this->_init_header())
		{
			$ret = ($this->head ? $this->_get_v2_info($cs) : $this->_get_v1_info());
			$ret['meta'] = $this->_get_meta_info();
		}
		$this->_file_deinit();
		return $ret;
	}

	// set info
	function set_info($file, $pa, $cs)
	{
		if (!$this->_file_init($file, true)) return false;
		$this->_init_header();

		// always save v1 info
		$this->_set_v1_info($pa);			
		// set v2 first if need
		$this->_set_v2_info($pa, $cs);

		$this->_file_deinit();
		return true;
	}

	// get the header information[v1+v2], call after file_init
	function _init_header()
	{
		$this->head1 = false;
		$this->head = false;

		// try to get ID3v1 first
		fseek($this->fd, -128, SEEK_END);
		$buf = fread($this->fd, 128);
		if (strlen($buf) == 128 && substr($buf, 0, 3) == 'TAG')
		{
			$tmp = unpack('a3id/a30Title/a30Artist/a30AlbumTitle/a4Year/a28Description/CReserved/CTrack/CGenre', $buf);
			$this->head1 = $tmp;			
		}

		// try to get ID3v2
		fseek($this->fd, 0, SEEK_SET);
		$buf = fread($this->fd, 10);
		if (strlen($buf) == 10 && substr($buf, 0, 3) == 'ID3') 
		{
			$tmp = unpack('a3id/Cver/Crev/Cflag/C4size', $buf);
			$tmp['size'] = ($tmp['size1']<<21)|($tmp['size2']<<14)|($tmp['size3']<<7)|$tmp['size4'];
			unset($tmp['size1'], $tmp['size2'], $tmp['size3'], $tmp['size4']);

			$this->head = $tmp;
			$this->head_buf = fread($this->fd, $tmp['size']);
		}
		return ($this->head1 || $this->head);
	}

	// get v1 info
	function _get_v1_info()
	{
		$ret = array();
		$tmpa = array('Title', 'Artist', 'Copyright', 'Description', 'Year', 'AlbumTitle');
		foreach ($tmpa as $tmp)
		{			
			$ret[$tmp] = $this->head1[$tmp];
			if ($pos = strpos($ret[$tmp], "\0")) 
				$ret[$tmp] = substr($ret[$tmp], 0, $pos);
		}

		// count the Genre, [Track]
		if ($this->head1['Reserved'] == 0) $ret['Track'] = $this->head1['Track'];
		else $ret['Description'] .= chr($ret['Reserved']) . chr($ret['Track']);

		// Genre_idx
		$g = $this->head1['Genre'];
		if (!isset($this->genres[$g])) $ret['Genre'] = 'Unknown';
		else $ret['Genre'] = $this->genres[$g];

		// return the value
		$ret['ID3v1'] = 'yes';
		return $ret;
	}

	// get v2 info
	function _get_v2_info($cs)
	{
		$ret = array();
		$items = array(	'TCOP'=>'Copyright', 'TPE1'=>'Artist', 'TIT2'=>'Title', 'TRCK'=> 'Track',
						'TCON'=>'Genre', 'COMM'=>'Description', 'TYER'=>'Year', 'TALB'=>'AlbumTitle');
		while (true)
		{
			$buf = $this->_read_head_buf(10);
			if (strlen($buf) != 10) break;			
			$tmp = unpack('a4fid/Nsize/nflag', $buf);
			if ($tmp['size'] == 0) break;
			$tmp['dat'] = $this->_read_head_buf($tmp['size']);

			// 0x6000 (11000000 00000000)		
			if ($tmp['flag'] & 0x6000) continue;			

			// mapping the data
			if ($k = $items[$tmp['fid']])
			{	// If first char is "\0", just skip
				$char = ord(substr($tmp['dat'], 0, 1));
				if ($char == 0) $ret[$k] = substr($tmp['dat'], 1);	// iso-8859-1
				else if ($char == 1) 									// ucs-2, utf-16 with bom
				{
					if (substr($tmp['dat'], 1, 2) === "\xfe\xff") 
						$ret[$k] = mb_convert_encoding(substr($tmp['dat'], 3), $cs, 'UTF-16BE');
					else if (substr($tmp['dat'], 1, 2) === "\xff\xfe")
						$ret[$k] = mb_convert_encoding(substr($tmp['dat'], 3), $cs, 'UTF-16LE');
					else
						$ret[$k] = mb_convert_encoding(substr($tmp['dat'], 1), $cs, 'UCS-2');					
				}
				else if ($char == 2)									// utf8-16be without bom
					$ret[$k] = mb_convert_encoding(substr($tmp['dat'], 1), $cs, 'UTF-16BE');
				else if ($char == 3)									// utf-8
					$ret[$k] = mb_convert_encoding(substr($tmp['dat'], 1), $cs, 'UTF-8');
				else
					$ret[$k] = $tmp['dat'];
			}
		}

		// reset the genre
		if ($g = $ret['Genre'])
		{
			if (substr($g,0,1) == '(' && substr($g,-1,1) == ')') $g = substr($g, 1, -1);
			if (is_numeric($g))
			{
				$g = intval($g);
				$ret['Genre'] = (isset($this->genres[$g]) ? $this->genres[$g] : 'Unknown');
			}
		}

		$ret['ID3v1'] = 'no';
		return $ret;
	}

	// get meta info of MP3
	function _get_meta_info()
	{
		// seek to the lead buf: 0xff
		$off = 0;
		if ($this->head) $off = $this->head['size'] + 10;
		fseek($this->fd, $off, SEEK_SET);
		while (!feof($this->fd))
		{
			$skip = ord(fread($this->fd, 1));
			if ($skip == 0xff) break;
		}
		if ($skip != 0xff) return false;
		$buf = fread($this->fd, 3);
		if (strlen($buf) != 3) return false;
		$tmp = unpack('C3', $buf);
		if (($tmp[1] & 0xf0) != 0xf0) return false;

		// get the meta info
		$meta = array();

		// get mpeg version
		$meta['mpeg']	= ($tmp[1] & 0x08 ? 1 : 2);
		$meta['layer']	= ($tmp[1] & 0x04) ? (($tmp[1] & 0x02) ? 1 : 2) : (($tmp[1] & 0x02) ? 3 : 0);
		$meta['epro']	= ($tmp[1] & 0x01) ? 'no' : 'yes';

		// bit rates
		$bit_rates = array(
			1 => array(
				1 => array(0,32,64,96,128,160,192,224,256,288,320,352,384,416,448,0),
				2 => array(0,32,48,56,64,80,96,112,128,160,192,224,256,320,384,0),
				3 => array(0,32,40,48,56,64,80,96,112,128,160,192,224,256,320,0)
			),
			2 => array(
				1 => array(0,32,48,56,64,80,96,112,128,144,160,176,192,224,256,0),
				2 => array(0,8,16,24,32,40,48,56,64,80,96,112,128,144,160,0),
				3 => array(0,8,16,24,32,40,48,56,64,80,96,112,128,144,160,0)
			)
		);
		$i = $meta['mpeg'];
		$j = $meta['layer'];
		$k = ($tmp[2]>>4);
		$meta['bitrate'] = $bit_rates[$i][$j][$k];

		// sample rates <采样率>
		$sam_rates = array(1=>array(44100,48000,32000,0), 2=>array(22050,24000,16000,0));
		$meta['samrate'] = $sam_rates[$i][$k];
		$meta["padding"] = ($tmp[2] & 0x02) ? 'on' : 'off';
		$meta["private"] = ($tmp[2] & 0x01) ? 'on' : 'off';

		// mode & mode_ext
		$k = ($tmp[3]>>6);
		$channel_modes = array('stereo', 'joint stereo', 'dual channel', 'single channel');
		$meta['mode'] = $channel_modes[$k];

		$k = (($tmp[3]>>4) & 0x03);
		$extend_modes = array('MPG_MD_LR_LR', 'MPG_MD_LR_I', 'MPG_MD_MS_LR', 'MPG_MD_MS_I');
		$meta['ext_mode'] = $extend_modes[$k];

		$meta['copyright'] = ($tmp[3] & 0x08) ? 'yes' : 'no';
		$meta['original'] = ($tmp[3] & 0x04) ? 'yes' : 'no';

		$emphasis = array('none', '50/15 microsecs', 'rreserved', 'CCITT J 17');
		$k = ($tmp[3] & 0x03);
		$meta['emphasis'] = $emphasis[$k];

		return $meta;
	}

	// set v1 info
	function _set_v1_info($pa)
	{
		// ID3v1 (simpled)
		$off = -128;
		if (!($tmp = $this->head1))
		{			
			$off = 0;
			$tmp['id'] = 'TAG';
			$tmp['Title'] = $tmp['Artist'] = $tmp['AlbumTitle'] = $tmp['Year'] = $tmp['Description'] = '';
			$tmp['Reserved'] = $tmp['Track'] = $tmp['Genre'] = 0;
		}
		
		// basic items
		$items = array('Title', 'Artist', 'Copyright', 'Description', 'Year', 'AlbumTitle');
		foreach ($items as $k)
		{
			if (isset($pa[$k])) $tmp[$k] = $pa[$k];
		}

		// genre index
		if (isset($pa['Genre']))
		{
			$g = 0;
			foreach ($this->genres as $gtmp)
			{
				if (!strcasecmp($gtmp, $pa['Genre'])) 
					break;
				$g++;
			}
			$tmp['Genre'] = $g;
		}
		if (isset($pa['Track'])) $tmp['Track'] = intval($pa['Track']);

		// pack the data
		$buf = pack('a3a30a30a30a4a28CCC',	$tmp['id'], $tmp['Title'], $tmp['Artist'], $tmp['AlbumTitle'],
						$tmp['Year'], $tmp['Description'], 0, $tmp['Track'], $tmp['Genre']);

		flock($this->fd, LOCK_EX);
		fseek($this->fd, $off, SEEK_END);
		fwrite($this->fd, $buf, 128);
		flock($this->fd, LOCK_UN);
	}

	// set v2 info
	function _set_v2_info($pa, $cs)
	{
		if (!$this->head)
		{	// insert ID3
			return;	// 没有就算了
			/**
			$tmp = array('id'=>'ID3','ver'=>3,'rev'=>0,'flag'=>0);
			$tmp['size'] = -10;	// +10 => 0
			$this->head = $tmp;
			$this->head_buf = '';
			$this->head_off = 0;
			**/
		}
		$items = array(	'TCOP'=>'Copyright', 'TPE1'=>'Artist', 'TIT2'=>'Title', 'TRAC'=>'Track',
						'TCON'=>'Genre', 'COMM'=>'Description', 'TYER'=>'Year', 'TALB'=>'AlbumTitle');

		$head_body = '';
		while (true)
		{
			$buf = $this->_read_head_buf(10);
			if (strlen($buf) != 10) break;
			$tmp = unpack('a4fid/Nsize/nflag', $buf);
			if ($tmp['size'] == 0) break;
			$data = $this->_read_head_buf($tmp['size']);

			if (($k = $items[$tmp['fid']]) && isset($pa[$k]))
			{
				// the data should prefix by "\0" [replace]
				if (preg_match('/[\x80-\xff]/', $pa[$k]))
					$data = "\1" . mb_convert_encoding($pa[$k], 'UCS-2', $cs);
				else
					$data = "\0" . $pa[$k];
				unset($pa[$k]);
			}
			$head_body .= pack('a4Nn', $tmp['fid'], strlen($data), $tmp['flag']) . $data;
		}
		// reverse the items & set the new tags
		$items = array_flip($items);
		foreach ($pa as $k => $v)
		{
			if ($fid = $items[$k])
			{
				$head_body .= pack('a4Nn', $fid, strlen($v) + 1, 0) . "\0" . $v;
			}
		}

		// new length
		$new_len = strlen($head_body) + 10;
		$old_len = $this->head['size'] + 10;
		if ($new_len < $old_len)
		{
			$head_body .= str_repeat("\0", $old_len - $new_len);
			$new_len = $old_len;			
		}

		// count the size1,2,3,4, no include the header
		// 较为变态的算法... :p (28bytes integer)
		$size = array();
		$nlen = $new_len - 10;
		for ($i = 4; $i > 0; $i--)
		{
			$size[$i] = ($nlen & 0x7f);
			$nlen >>= 7;
		}
		$tmp = $this->head;
		//echo "old_len : $old_len new_len: $new_len\n";
		$head_buf = pack('a3CCCCCCC', $tmp['id'], $tmp['ver'], $tmp['rev'], $tmp['flag'],
			$size[1], $size[2], $size[3], $size[4]);
		$head_buf .= $head_body;
		$this->_file_save($head_buf, $old_len, $new_len);
	}
}
?>