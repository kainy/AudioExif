<?php
 header('content-type: text/html; charset= utf-8');
 require ('AudioExif.class.php');
 $AE = new AudioExif($charset = 'utf8');
 $file = 'test.mp3';

// 1. 检查文件是否完整 (only for wma, mp3始终返回 true)
 
// $AE->CheckSize($file);

// 2. 读取信息, 返回值由信息组成的数组, 键名解释参见上方
 echo '//////////////////////////////////////// 读取文件信息 ////////////////////////////////////////////////';
 print_r($AE->GetInfo($file));

// 3. 写入信息, 第二参数是一个哈希数组, 键->值, 支持的参见上方的, mp3也支持 Track
//    要求第一参数的文件路径可由本程序写入
 $pa = array('Title'=> 'T标题',
			 'Artist'=> 'A艺术家',
			 'Copyright'=> 'YaYue.fm',
			 'Description'=> '',
			 'Track'=> '',
			 'Genre'=> '',
			 'AlbumTitle'=> '');
 $AE->SetInfo($file, $pa);
 echo '//////////////////////////////////////// 写入后重新读取 ////////////////////////////////////////////////';
  print_r($AE->GetInfo($file));
?>