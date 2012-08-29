AudioExif.class.php
用PHP进行音频文件头部信息的读取与写入
目前只支持 WMA 和 MP3 两种格式, 只支持常用的几个头部信息



## 写入信息支持 ##

- Title(名称)
- Artist(艺术家)
- Copyright(版权)
- Description (描述)
- Year(年代)
- Genre (流派)
- AlbumTitle (专辑标题)

其中 mp3 和 wma 略有不同, 具体返回的信息还可能更多, 但只有以上信息可以被写入；

mp3还支持 Track (曲目编号写入)；

对于 MP3 文件支持 ID3v1也支持ID3v2, 读取时优先 v2, 写入时总是会写入v1, 必要时写入v2。



## 用法说明  ##
由于 wma 使用 Unicode 存取, 故还需要 mb_convert_encoding() 扩展返回数据及写入数据均为 ANSI 编码, 即存什么就显示什么 (中文_GB2312)

`require ('AudioExif.class.php');`

`$AE = new AudioExif([$charset = 'GBK']);`

`$file = '/path/to/test.mp3';`

`//1. 检查文件是否完整 (only for wma, mp3始终返回 true)`

`$AE->CheckSize($file);`

`//2. 读取信息, 返回值由信息组成的数组, 键名解释参见上方`

`print_r($AE->GetInfo($file));`

`//3. 写入信息, 第二参数是一个哈希数组, 键->值, 支持的参见上方的, mp3也支持 Track要求第一参数的文件路径可由本程序写入`

`$pa = array('Title' ='新标题', 'AlbumTitle' ='新的专辑名称');`

`$AE->SetInfo($file, $pa);`



## 更新历史 ##

-  Kainy.20120829:强制写入id3信息，避免因mp3标签类型为APEv2而导致写入信息失败的问题。

-  hightman.20101010: v0.2更好的支持编码转换, 类对像可以传入 charset 参数, 默认为 gbk.

 - 1) 读取信息则统一返回指定的编码, 以便获得的信息, ID3v1则没有编码直接返回原字符串
 - 2) 写入时id3v2和wma转为ucs-2存储, id3v1均不作转换按iso-8859-1存入.



## ID3v2编码规范 ##

- $00 – ISO-8859-1 (ASCII).
- $01 – UCS-2 in ID3v2.2 and ID3v2.3, UTF-16 encoded Unicode with BOM.(FE FF, big-endian, FF FE, little-endian)
- $02 – UTF-16BE encoded Unicode without BOM in ID3v2.4 only.
- $03 – UTF-8 encoded Unicode in ID3v2.4 only.



## 其他说明 ##

> 版本: 0.2

> 作者: hightman

> QQ群: 17708754  (非纯PHP进阶交流群)

> 时间: 2007/01/25

> 其它: 该插件花了不少时间搜集查找 wma及mp3 的文件格式说明文档与网页, 希望对大家有用.其实网上已经有不少类似的程序, 但对 wma 实在太少了, 只能在 win 平台下通过 M$ 的API 来操作, 而 MP3 也很少有可以在 unix/linux 命令行操作的, 所以特意写了这个模块,如果发现 bug 或提交 patch, 或加以改进使它更加健壮, 请告诉我. (关于 ID3和Wma的文件格式及结构 在网上应该都可以找到参考资料)
