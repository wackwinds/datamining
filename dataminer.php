<?php

include_once ('base.php');

abstract class DataMiner extends ScpritBase{
	// 以下字段用于数据次数统计，对应上面的变量$resultStatistics，使用时在程序运行过程中$this->resultStatistics[对应数组元素]++;即可
	protected $statisticsKeys = array(
	);

	// 输出的数据文件与结果文件的名称
	protected $fileNameData = null;
	protected $fileNameResult = 'miningResult.txt';
	protected $fileNameSource = null;
	// 输出文件的文件夹
	protected $folderName = 'dataMining';

	// 打日志时的日志前缀与结果文件里的脚本标题，用于grep相应信息
	protected $scriptTitle = 'dataMining';

	protected $execParams = array(
	);

	protected $commands = array(
	);

	// ---------+-------------+------------- self defines -----------+------------+----------
	protected $dealtFiles = null;
	protected $baseAddress = '.'; // 基准文件夹：默认当前文件夹
	protected $folderDataRecord = 'dataRecord';
	protected $dataFileDealtMD5s = 'dealtMD5s.txt';

	protected $delimiterDealtMD5s = "\n";

	/**
	 * 构造函数
	 */
	public function __construct()
	{
		parent::__construct();

		$dataRecordFolder = $this->baseAddress . '/' . $this->folderDataRecord;
		if (!is_dir($dataRecordFolder)){
			if (!mkdir($dataRecordFolder)){
				$this->printWarning(1, true, $dataRecordFolder);
			}
		}
	}

	/**
	 *
	 */
	protected function exec(){
		$targetFiles = $this->getTextFiles();
		$dealtMD5s = $this->getDealtMD5s();

		foreach($targetFiles as $file){
			$fileMd5 = md5_file($file);
			if (in_array($fileMd5, $dealtMD5s)){
				$this->printLog('file already dealt' . $file, true, $file);
			}else{
				$ret = $this->dealWithTargetFile($file);
				if (!$this->isDebug){
					$dealtMD5s[] = $fileMd5;
				}
			}
		}

		$ret = $this->setDealtMD5s($dealtMD5s);
		if (!$ret){
			$this->printWarning(2, true, $dealtMD5s);
		}
	}

	/**
	 * 子类复写
	 * 对目标文件进行处理
	 */
	protected function dealWithTargetFile($targetFile){
		$ret = true;
		$content = file_get_contents($targetFile);
		$arrContents = $this->preDealWithTargetFile($content, $targetFile);
		$splitResult = $this->splitText($arrContents);
		$this->outputSplitResult($splitResult, $targetFile);

		return $ret;
	}

	/**
	 * 子类复写
	 * @param $splitResult
	 */
	protected function outputSplitResult($splitResult, $targetFile){

	}

	/**
	 * 子类复写
	 * @param $text
	 */
	protected function splitText($text){
		$cws = scws_open();
		scws_set_charset($cws, "utf8");
		scws_set_dict($cws, ini_get('scws.default.fpath') . '/dict.utf8.xdb');
		scws_set_rule($cws, ini_get('scws.default.fpath') . '/rules.utf8.ini');
//scws_set_ignore($cws, true);
//scws_set_multi($cws, true);
		scws_send_text($cws, $text);

		echo "\n";

// top words
		printf("No. WordString               Attr  Weight(times)\n");
		printf("-------------------------------------------------\n");
		$list = scws_get_tops($cws, 1000, "~v");
		$cnt = 1;
		foreach ($list as $tmp)
		{
			printf("%02d. %-24.24s %-4.2s  %.2f(%d)\n",
				$cnt, $tmp['word'], $tmp['attr'], $tmp['weight'], $tmp['times']);
			$cnt++;
		}

		echo "\n\n-------------------------------------------------\n";
// segment
		/*while ($res = scws_get_result($cws))
		{
			foreach ($res as $tmp)
			{
				if ($tmp['len'] == 1 && $tmp['word'] == "\r")
					continue;
				if ($tmp['len'] == 1 && $tmp['word'] == "\n")
					echo $tmp['word'];
				else
					printf("%s/%s ", $tmp['word'], $tmp['attr']);
			}
		}*/
		echo "\n\n";

		scws_close($cws);
	}

	/**
	 * 子类可通过对本函数的重写进行一些预处理
	 * @param $arrContent
	 * @param null $targetFile // 用于识别出进行哪种处理
	 * @return mixed
	 */
	protected function preDealWithTargetFile($content, $targetFile = null){
		$arrContents = explode("\n", $content);
		return $arrContents;
	}

	/**
	 * 获取处理过的md5数组
	 * @return array
	 */
	protected function getDealtMD5s(){
		$dataRecordFolder = $this->baseAddress . '/' . $this->folderDataRecord;
		$dealtFile = $dataRecordFolder . '/' . $this->dataFileDealtMD5s;
		$content = file_get_contents($dealtFile);

		$md5s = array();
		if (!empty($content)){
			$md5s = explode($this->delimiterDealtMD5s, $content);
		}

		return $md5s;
	}

	/**
	 * 将处理过的md5回写到文件中
	 * @param $md5s
	 * @return int
	 */
	protected function setDealtMD5s($md5s){
		$dataRecordFolder = $this->baseAddress . '/' . $this->folderDataRecord;
		$dealtFile = $dataRecordFolder . '/' . $this->dataFileDealtMD5s;
		$content = implode($this->delimiterDealtMD5s, $md5s);

		$ret = file_put_contents($dealtFile, $content);

		return $ret;
	}

	/**
	 * 返回数组类型的一系列文件地址
	 * @return mixed
	 */
	abstract protected function getTextFiles();

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
	}
}
?>

