<?php

include_once ('dataminer.php');
include_once ('util.php');

class pholcus extends DataMiner{
	// 以下字段用于数据次数统计，对应上面的变量$resultStatistics，使用时在程序运行过程中$this->resultStatistics[对应数组元素]++;即可
	protected $statisticsKeys = array(
	);

	// 输出的数据文件与结果文件的名称
	protected $fileNameData = null;
	protected $fileNameResult = 'miningResultPholcus.txt';
	protected $fileNameSource = null;
	// 输出文件的文件夹
	protected $folderName = 'dataMiningPholcus';

	// 打日志时的日志前缀与结果文件里的脚本标题，用于grep相应信息
	protected $scriptTitle = 'dataMiningPholcus';

	protected $execParams = array(
	);

	protected $commands = array(
	);

	// ----------------- self defines ---------------------
	private $textFolder = '/home/users/huangweiqi/my/pholcus_pkg/text_out';
	private $fileTypeMapping = array(
		self::FILE_TYPE_HWQTEST => 'hwqTest_',
		self::FILE_TYPE_WANGYI => '网易新闻_',
	);
	private $folderResultWangyi = 'wangyi';

	const DATA_POS_WANGYI_TITLE = 0;
	const DATA_POS_WANGYI_CONTENT = 1;
	const DATA_POS_WANGYI_RANK = 2;
	const DATA_POS_WANGYI_TYPE = 3;
	const DATA_POS_WANGYI_RELEASE_TIME = 4;
	const DATA_POS_WANGYI_CURRENT_LINK = 5;
	const DATA_POS_WANGYI_NEXT_LINK = 6;
	const DATA_POS_WANGYI_DOWNLOAD_TIME = 7;

	const FILE_PATH_WANGYI_TIME_DPOS = 3; // 网易文件路径中，时间所处的位置在倒数第几个

	const VALID_TITLE_LEN = 2; // 只有符合长度的title才进行分词操作，用于过滤掉一些乱七八糟的数据

	const FILE_TYPE_HWQTEST = 'hwqTest';
	const FILE_TYPE_WANGYI = 'wangyi';
	/**
	 * 构造函数
	 */
	public function __construct()
	{
		parent::__construct();
	}

	/**
	 *
	 */
	protected function exec(){
		parent::exec();
	}

	/**
	 * @return array
	 */
	protected function getTextFiles(){
		$targetFiles = $this->getAllTextFiles($this->textFolder);
		return $targetFiles;
	}

	/**
	 * @param $rootFolder
	 * @return array
	 */
	private function getAllTextFiles($rootFolder){
		$targetDir = dir($rootFolder);
		$textFiles = array();
		while($file = $targetDir->read()){
			if ($file == '.' || $file == '..'){
				continue;
			}

			$target = $rootFolder . '/' . $file;
			if (is_dir($target)){
				$tempFiles = $this->getAllTextFiles($target);
				$textFiles = array_merge($textFiles, $tempFiles);
			}else{
				$textFiles[] = $target;
			}
		}

		return $textFiles;
	}

	/**
	 *
	 */
	protected function finished(){
		// do some finished work
		parent::finished();
	}

	/**
	 *
	 */
	protected function init(){
		// init
		parent::init();

		if(!extension_loaded('scws')) {
			dl('scws.' . PHP_SHLIB_SUFFIX);
		}
	}

	// --------+---------+-----------

	/**
	 * 子类可通过对本函数的重写进行一些预处理
	 * @param $arrContents
	 * @return mixed
	 */
	protected function preDealWithTargetFile($content, $targetFile = null){
		$fileType = null;

		foreach($this->fileTypeMapping as $key => $value){
			if (false !== mb_strpos($targetFile, $value)){
				$fileType = $key;
				break;
			}
		}

		switch($fileType){
			case self::FILE_TYPE_HWQTEST:
				$arrContents = $this->getHwqTestContents($content);
				break;
			case self::FILE_TYPE_WANGYI:
				$fileHandle = fopen($targetFile, 'r');
				$arrContents = null;
				$isFirst = true;
				while ($arrLine = fgetcsv($fileHandle)){
					// 跳过标题
					if ($isFirst){
						$isFirst = false;
						continue;
					}

					$arrContents[] = $arrLine;
				}

				$arrContents = $this->getWangyiContents($arrContents);
				break;
			default:
				$arrContents = explode("\n", $content);
		}

		return $arrContents;
	}

	protected function splitText($arrContent){
		$splitResult = null;
		foreach($arrContent as $title => $content){
			$titleLen = mb_strlen($title, 'utf-8');
			if ($titleLen != self::VALID_TITLE_LEN){
				continue;
			}

			$ret = $this->split($content);
			$splitResult[$title] = $ret;
		}
		return $splitResult;
	}

	protected function outputSplitResult($splitResult, $targetFile){
		// 获取文件对应的时间
		$arrFileParts = explode('/', $targetFile);
		$time = $arrFileParts[count($arrFileParts) - self::FILE_PATH_WANGYI_TIME_DPOS];

		// 时间格式处理
		$time = str_replace(' ', '', $time);
		preg_match_all('/\d/', $time, $arr);
		$time=implode('',$arr[0]);

		// 建文件夹
		$wangyiFolder = $this->folderPath . '/' . $this->folderResultWangyi;
		if (!is_dir($wangyiFolder)){
			mkdir($wangyiFolder);
		}

		$resultFolder = $wangyiFolder . '/' . $time;
		if (!is_dir($resultFolder)){
			mkdir($resultFolder);
		}

		foreach($splitResult as $title => $content){
			$fileName = $title . '.txt';
			file_put_contents($resultFolder . '/' . $fileName, json_encode($content));
		}
	}

	private function split($text){
		$cws = scws_open();
		scws_set_charset($cws, "utf8");
		scws_set_dict($cws, ini_get('scws.default.fpath') . '/dict.utf8.xdb');
		scws_set_rule($cws, ini_get('scws.default.fpath') . '/rules.utf8.ini');
		scws_send_text($cws, $text);

		$ret = null;
		$result = true;
		while($result){
			$result = scws_get_result($cws);
			if (is_array($result)){
				foreach($result as $wordInfo){
					$ret[$wordInfo['word']]['word'] = $wordInfo['word'];
					$ret[$wordInfo['word']]['attr'] = $wordInfo['attr'];
					$ret[$wordInfo['word']]['count']++;
				}
			}
		}

		scws_close($cws);
		return $ret;
	}

	/**
	 * @param $arrContents
	 * @return mixed
	 */
	private function getHwqTestContents($content){
		$arrContents = explode("\n", $content);

		// todo
		return array();

		return $arrContents;
	}

	/**
	 * @param $content
	 * @return array
	 */
	private function getWangyiContents($arrContents){
		$newArrContent = null;
		foreach($arrContents as $content){
			$newArrContent[$content[self::DATA_POS_WANGYI_TYPE]] .= $content[self::DATA_POS_WANGYI_CONTENT];
		}

		return $newArrContent;
	}

}

// run the job
$pholcus = new pholcus();
$pholcus->run();
?>

