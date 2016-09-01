<?php

include_once ('base.php');

/**
 * 对分词后的结果做二次处理
 * 需要与dataMining运行在同一文件夹内
 * Class DataRenewer
 */
class DataRenewer extends ScpritBase{
	// 输出的数据文件与结果文件的名称
	protected $fileNameData = null;
	protected $fileNameResult = 'renewingResult.txt';
	protected $fileNameSource = null;
	// 输出文件的文件夹
	protected $folderName = 'dataRenewing';

	// 打日志时的日志前缀与结果文件里的脚本标题，用于grep相应信息
	protected $scriptTitle = 'dataRenewing';

	protected $execParams = array(
	);

	protected $commands = array(
		'-runTips' => array(
			'type' => self::COMMAND_TYPE_VALUE,
			'tips' => "recommend run command: -topNNum 3000 -disAllowAttr un,uj",
			'param' => "xyz",
		),
		'-forceToParse' => array(
			'type' => self::COMMAND_TYPE_BOOL,
			'tips' => "force to parse all raw files(other wise, dealt files will pass by)",
			'param' => "forceParse",
		),
		'-outputType' => array(
			'type' => self::COMMAND_TYPE_VALUE,
			'tips' => "output type(topN, wordTrace[must set tracewords])",
			'param' => "outputType",
		),
		'-topNNum' => array(
			'type' => self::COMMAND_TYPE_VALUE,
			'tips' => "when output type=topN, specify the N",
			'param' => "topNNum",
		),
		'-tracewords' => array(
			'type' => self::COMMAND_TYPE_VALUE,
			'tips' => "when output type is wordTrace, set target trace words, implode by ,",
			'param' => "traceWords",
		),
		'-tracefiles' => array(
			'type' => self::COMMAND_TYPE_VALUE,
			'tips' => "when output type is wordTrace, set target trace files, implode by , support type 'all'",
			'param' => "traceFiles",
		),
		'-allowAttr' => array(
			'type' => self::COMMAND_TYPE_VALUE,
			'tips' => "only list attr will be allow to stay, ','to be explode value. (values see: http://www.xunsearch.com/scws/docs.php)",
			'param' => "allowAttr",
		),
		'-disAllowAttr' => array(
			'type' => self::COMMAND_TYPE_VALUE,
			'tips' => "list attr will not allow to stay, ','to be explode value. (values see: http://www.xunsearch.com/scws/docs.php)",
			'param' => "disAllowAttr",
		),
	);

	// ---------+-------------+------------- self defines -----------+------------+----------
	const ERRNO_NO_TRACE_WORD = 100000; // traceWord模式下缺少tracewords
	const ERRNO_NO_TRACE_WORD_DUPLICATE = 100001; // traceWord模式下一个单词在文件中出现了多次（即结果统计不准）

	const FILE_NAME_SUFFIX = '.txt';

	const POS_FILE_TIME = 11;
	const POS_FILE_NAME = 12;

	const OUTPUT_TYPE_TOP_N = 'topN';
	const OUTPUT_TYPE_WORD_TRACE = 'wordTrace';

	protected $dealtFiles = null;
	protected $baseAddress = '.'; // 基准文件夹：默认当前文件夹
	protected $folderRawData = 'dataMiningPholcus';
	protected $forceParse = false; // 是否强制进行文件解析，为false时对已解析过的文件会直接跳过
	protected $outputType = self::OUTPUT_TYPE_TOP_N; // 输出类型: topN
	protected $topNNum = 99; // 输出类型为topN时，N的大小
	protected $miningData = null;
	protected $debugMaxDealFileNum = 10;
	protected $allowAttr = null; // 允许输出的分词属性，以,进行分隔
	protected $disAllowAttr = null; // 不允许输出的分词属性，以,进行分隔
	protected $traceWords = null; // 根据目标单词进行输出
	protected $traceFiles = null; // 单词跟踪模式，只输出对应文件的统计结果

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
		$targetFiles = $this->getTextFiles();

		$total = count($targetFiles);
		$idx = 0;
		foreach($targetFiles as $file){
			$idx++;
			$this->PrintLog("dealing with ($idx/$total)", true);

			$resultFilePath = str_replace($this->folderRawData, $this->folderName, $file);

			// 若文件已存在，则不再进行处理，除非开启了强制解析开关
			if (is_file($resultFilePath) && !$this->forceParse){
				$this->resultStatistics['existFileNum']++;
				continue;
			}

			$content = file_get_contents($file);
			$arrContent = json_decode($content, true);
			if (empty($arrContent)){
				$this->resultStatistics['emptyFileContentNum']++;
				$this->PrintLog("empty file content: " . $file, true);
				continue;
			}

			// 数据过滤
			$arrContent = $this->workFilter($arrContent);

			$this->dealWithTargetFile($arrContent, $file);

			if ($this->isDebug && $idx == $this->debugMaxDealFileNum){
				break;
			}
		}
		$this->outputContent();
	}

	/**
	 * @param $arrWords
	 * @return null
	 */
	protected function workFilter($arrWords){
		if (!empty($this->allowAttr) && is_array($arrWords)){
			$arrAllowAttrs = explode(',', $this->allowAttr);
			$newArrWords = null;
			foreach($arrWords as $key => $value){
				if (in_array($value['attr'], $arrAllowAttrs)){
					$newArrWords[$key] = $value;
				}
			}
			$arrWords = $newArrWords;
		}

		if (!empty($this->disAllowAttr) && is_array($arrWords)){
			$arrDisAllowAttrs = explode(',', $this->disAllowAttr);
			$newArrWords = null;
			foreach($arrWords as $key => $value){
				if (!in_array($value['attr'], $arrDisAllowAttrs)){
					$newArrWords[$key] = $value;
				}
			}
			$arrWords = $newArrWords;
		}

		return $arrWords;
	}

	/**
	 * @return bool
	 */
	protected function outputContent(){
		switch($this->outputType){
			case self::OUTPUT_TYPE_TOP_N:
				// miningData结构：key为单词，value为单词信息数组
				$this->miningData = BdBus_Util_Sort::sortByTargetKey($this->miningData, 'count', SORT_DESC);
				$miningData = array_slice($this->miningData, 0, $this->topNNum);
				$idx = 0;
				foreach($miningData as $data){
					$idx++;
					echo $idx . "\t" . $data['word'] . "\t" . $data['count'] . "\t" . $data['attr'] . "\n";
				}
				break;
			case self::OUTPUT_TYPE_WORD_TRACE:
				// miningData结构：单词->时间->文件名->单词信息数组
				$arrTraceFiles = null;
				if (!empty($this->traceFiles)){
					$arrTraceFiles = explode(',', $this->traceFiles);
				}

				foreach($this->miningData as $word => $timeInfo){
					foreach($timeInfo as $time => $fileInfo){
						foreach($fileInfo as $fileName => $wordInfo){
							// 只输出指定文件里的统计结果
							if (is_array($arrTraceFiles)){
								$arrTempFileName = explode(self::FILE_NAME_SUFFIX, $fileName);
								if (!in_array($arrTempFileName[0], $arrTraceFiles)){
									continue;
								}
							}

							echo $word . "\t" . $time . "\t" . $fileName . "\t" . $wordInfo['count'] . "\t" . $wordInfo['attr'] . "\n";
						}
					}
				}
				break;
			default:
				$this->PrintLog('unkown output type', true);
				;
		}

		return true;
	}

	/**
	 * 子类复写
	 * 对目标文件进行处理
	 */
	protected function dealWithTargetFile($arrFileContent, $filePath = null){
		switch($this->outputType){
			case self::OUTPUT_TYPE_TOP_N:
				$this->dealerTopN($arrFileContent);
				break;
			case self::OUTPUT_TYPE_WORD_TRACE:
				$this->dealerWordTrace($arrFileContent, $filePath);
				break;
			default:
				$this->PrintLog('unkown output type', true);
				;
		}

		return true;
	}

	/**
	 * @return array
	 */
	protected function getTextFiles(){
		$arrFilePath = explode('/', $this->folderPath);
		$arrPartNum = count($arrFilePath);
		unset($arrFilePath[$arrPartNum - 1]);
		$arrFilePath[] = $this->folderRawData;
		$targetFilePath = implode('/', $arrFilePath);

		$targetFiles = $this->getAllTextFiles($targetFilePath);
		return $targetFiles;
	}

	/**
	 * @param $arrFileContent
	 */
	private function dealerTopN($arrFileContent){
		foreach($arrFileContent as $wordInfo){
			$keyWord = $wordInfo['word'];
			if (empty($this->miningData[$keyWord])){
				$this->miningData[$keyWord] = $wordInfo;
				continue;
			}else{
				$this->miningData[$keyWord]['count'] += $wordInfo['count'];
			}
		}
	}

	/**
	 * @param $arrFileContent
	 * @param $filePath
	 */
	private function dealerWordTrace($arrFileContent, $filePath){
		if (empty($this->traceWords)){
			$this->printWarning(self::ERRNO_NO_TRACE_WORD, true, null, null, 'error！ lack of trace words');
			exit(self::ERRNO_NO_TRACE_WORD);
		}

		// 获取文件时间
		$arrFilePathInfo = explode('/', $filePath);
		$fileTime = $arrFilePathInfo[self::POS_FILE_TIME];
		$fileName = $arrFilePathInfo[self::POS_FILE_NAME];

		$arrTraceWords = explode(',', $this->traceWords);

		$testDupMap = null;
		foreach($arrFileContent as $wordInfo){
			if (!in_array($wordInfo['word'], $arrTraceWords)){
				continue;
			}

			$this->miningData[$wordInfo['word']][$fileTime][$fileName] = $wordInfo;
			$this->miningData[$wordInfo['word']][$fileTime]['all.txt']['count'] += $wordInfo['count'];

			$testDupMap[$wordInfo['word']]++;
			if ($testDupMap[$wordInfo['word']] > 1){
				$this->printWarning(self::ERRNO_NO_TRACE_WORD, true, $wordInfo, $testDupMap, 'duplicate word in traceWords mode: ' . $wordInfo['word']);
			}
		}
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
	}
}

$job = new DataRenewer();
$job->run();

?>

