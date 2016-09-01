<?php
/**
 * 脚本基类
 * @author 黄伟琦(huangweiqi@baidu.com)
 */
Bd_Init::init();

ini_set('memory_limit', '6144M');
ini_set('max_execution_time', 0);

class ScpritBase{
    // common
    protected $objLog = null;
    protected $sourceContent = null; // 来源于文件的内容
    protected $resultContent = null; // 最终输出到结果文件的内容
    protected $resultStatistics = null; // 数字类统计，最终也会输出到结果文件里
    protected $folderPath = null; // 目标文件夹
    protected $resultFilePath = null; // 结果文件路径
    protected $sourceFilePath = null; // 源文件路径
    protected $dataFilePath = null; // 数据文件路径
    protected $dataContent = null; // 用于保存每次运行时内容较多，便于进行数据恢复或问题查询的中间结果
    protected $execParams = null; // 执行COMMAND_TYPE_EXCECUTE这一类方法时的传入参数

    protected $isDebug = false; // 开启debug模式，可输出一些自定义debug信息
    protected $isTest = false; // 开启这一模式后一般都不会实际向数据库进行写操作，并有可能对一些参数使用rand方式生成

    // 以下字段用于数据次数统计，对应上面的变量$resultStatistics，使用时在程序运行过程中$this->resultStatistics[对应数组元素]++;即可
    protected $statisticsKeys = array(
        'testNum',
        'debugNum',
    );

    // 输出的数据文件与结果文件的名称
    protected $fileNameData = 'multiRegion.txt';
    protected $fileNameResult = 'setRegionShownName.txt';
    // 输出文件的文件夹
    protected $folderName = 'temp';

    // 输入文件名
    protected $fileNameSource = 'source.txt';

    // 打日志时的日志前缀与结果文件里的脚本标题，用于grep相应信息
    protected $scriptTitle = 'script base class';

    protected $maxResultFileSize = 100000000; // 结果文件的最大大小，单位：字节，当前取值：100M

    const COMMAND_TYPE_BOOL = 'bool'; // param取值就会为true
    const COMMAND_TYPE_VALUE = 'value'; // param取值为cmd后一位参数
    const COMMAND_TYPE_EXCECUTE = 'exec'; // 执行func里对应的函数

    /**
     * 支持以下功能：
     * type: cmd类型，参考上面的COMMAND_系列参数
     * tips: -h时显示的参数说明
     * param: type=COMMAND_TYPE_VALUE | COMMAND_TYPE_BOOL时对应要赋值的变量名
     * func: type=COMMAND_TYPE_EXCECUTE时要执行的函数
     * depends: 依赖于哪些变量，当本变量被赋值时，所依赖变量必须全都完成了赋值，若没有赋值，则报错
     * necessary: 本变量是否必须要进行设置，若没有赋值，则报错
     */
    protected $defaultCommands = array(
        '-d' => array(
            'type' => self::COMMAND_TYPE_BOOL,
            'tips' => "run in debug mode, thie mode will output some debug info",
            'param' => "isDebug",
        ),
        '-t' => array(
            'type' => self::COMMAND_TYPE_BOOL,
            'tips' => "test the script, the param will not write to db, it's just statistics",
            'param' => "isTest",
        ),
        '-h' => array(
            'type' => self::COMMAND_TYPE_EXCECUTE,
            'tips' => "show usage",
            'func' => "usage",
        ),
    );
    protected $commands = array(); // 继承类自己添加对应的cmd


    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->objLog = BdBus_Factory_Log::getInstance();
    }

    /**
     * @return int
     */
    protected function exec(){
        echo "start to work\n";
        return BdBus_Const_Errs_System::SYS_SUCC;
    }

    /**
     * @param array $input
     * @return mixed|void
     */
    public function run()
    {
        // start to record the time
        $startTime = BdBus_Util_Time::getMicrotimeFloat();

        // arg parse and init
        $this->parseArgs();
        $this->init();

        // start to do the job
        $this->printLog('process start');

        // work
        $ret = $this->exec();

        // finished
        $this->finished();

        // record total cost time
        $endTime = BdBus_Util_Time::getMicrotimeFloat();
        var_dump('run succ in ' . ($endTime - $startTime) . ' secs');

        return $ret;
    }

    /**
     *
     */
    protected function finished(){
        // do some finished work
        if (!empty($this->resultFilePath)){
            $this->addResultContentCount();
            $this->resultContent .= '---------------- end at: ' . date('Y-m-d H:i:s', time()) . ' ---------------------' . "\n\n";
            file_put_contents($this->resultFilePath, $this->resultContent, FILE_APPEND);
            $msg = "result operations: \n";
            echo $msg;
            $msg = 'vi ' . $this->resultFilePath . "\n";
            echo $msg;
            $msg = 'cat ' . $this->resultFilePath . "\n";
            echo $msg;
            $msg = 'sz ' . $this->resultFilePath . "\n";
            echo $msg;
        }

        if (!empty($this->dataFilePath)){
            file_put_contents($this->dataFilePath, $this->dataContent);
            $msg = "data operations: \n";
            echo $msg;
            $msg = 'vi ' . $this->dataFilePath . "\n";
            echo $msg;
            $msg = 'cat ' . $this->dataFilePath . "\n";
            echo $msg;
            $msg = 'sz ' . $this->dataFilePath . "\n";
            echo $msg;
        }
    }

    /**
     *
     */
    protected function addResultContentCount(){
        foreach($this->statisticsKeys as $key){
            $this->resultContent .= "$key: " . $this->resultStatistics[$key] . "\n";
        }
    }

    /**
     *
     */
    protected function init(){
        // init
        $this->resultContent = '---------------- ' . $this->scriptTitle . ' ---------------------' . "\n";
        $this->resultContent .= '---------------- start at: ' . date('Y-m-d H:i:s', time()) . ' ---------------------' . "\n";

        $strMode = 'mode: ';
        if ($this->isDebug){
            $strMode .= "debug ";
        }
        if ($this->isTest){
            $strMode .= 'test ';
        }
        $strMode .= "\n";
        $this->resultContent .= $strMode;

        $this->folderPath = Bd_AppEnv::getEnv('data') . '/' . $this->folderName;
        if (!file_exists($this->folderPath)){
            if (!mkdir($this->folderPath)){
                var_dump('create result folder failed');
            }
        }
        if (!empty($this->fileNameResult)){
            $this->resultFilePath = $this->folderPath . '/' . $this->fileNameResult;
            $fileSize = filesize($this->resultFilePath);
            if ($fileSize > $this->maxResultFileSize){
                unlink($this->resultFilePath);
            }
        }

        if (!empty($this->fileNameData)){
            $this->dataFilePath = $this->folderPath . '/' . $this->fileNameData;
            unlink($this->dataFilePath);
        }

        if (!empty($this->fileNameSource)){
            $this->sourceFilePath = $this->folderPath . '/' . $this->fileNameSource;
            $this->sourceContent = file_get_contents($this->sourceFilePath);
        }
    }

    /**
     * @param null $params
     */
    protected function usage($params = null){
        $commands = $this->getCommands();

        echo "\n\n";
        echo "     ||       ||   ===     ===     ===     *******                          \n";
        echo "     ||       ||    ===   =====   ===     **     **                         \n";
        echo "     ||=======||     === === === ===     **       **                        \n";
        echo "     ||       ||      =====   =====       **     ***                        \n";
        echo "     ||       ||       ===     ===         ******* ***                      \n";
        echo "\n";
        echo "script writen by huangweiqi@baidu.com\n";
        echo "how to run : {ODP_ROOT}/php/bin/php {this script} {params}\n";
        echo "supported params:\n";

        foreach($commands as $cmdSymbol => $cmdInfo){
            echo $cmdSymbol . " : " . $cmdInfo['tips'] . "\n";
        }

        echo "\n\n";

        exit(0);
    }

    /**
     *
     */
    protected function parseArgs(){
        $commands = $this->getCommands();

        for ($i = 1; $i < $_SERVER['argc']; $i++) {
            $cmd = $_SERVER['argv'][$i];
            $targetCmd = $commands[$cmd];
            if (!empty($targetCmd)){
                switch($targetCmd['type']){
                    case self::COMMAND_TYPE_BOOL:
                        $this->{$targetCmd['param']} = true;
                        break;
                    case self::COMMAND_TYPE_VALUE:
                        $this->{$targetCmd['param']} = $_SERVER['argv'][$i + 1];
                        break;
                    case self::COMMAND_TYPE_EXCECUTE:
                        $this->{$targetCmd['func']}($this->execParams);
                        break;
                }
            }
        }

        foreach($commands as $key => $cmd){
            if ($cmd['necessary']){
                if (!isset($this->{$cmd['param']})){
                    echo "\n\n--------- !!! ------------\n\n";
                    $msg = "error: lack of '" . $key . "', see usage\n\n";
                    echo $msg;
                    echo "--------- !!! ------------\n";
                    $this->usage();
                }
            }

            if (isset($this->{$cmd['param']}) && !empty($cmd['depends'])){
                foreach($cmd['depends'] as $dependParam){
                    if (!isset($this->{$dependParam})){
                        echo "--------- !!! ------------\n\n";
                        $msg = "error: param '" . $cmd['param'] . "' depends on '" . $dependParam . "'\n";
                        echo $msg;
                        echo "--------- !!! ------------\n";
                        $this->usage();
                    }
                }
            }
        }
    }

    /**
     * @param $msg
     * @param bool $showOut
     * @param null $input
     * @param null $output
     * @param null $errno
     * @param bool $printStack
     */
    protected function printLog($msg, $showOut = false, $input = null, $output = null, $errno = null, $printStack = false){
        $newMsg = $this->scriptTitle . ': ' . $msg;

        if ($showOut){
            echo $newMsg . "\n";
        }

        $this->objLog->traceEX($newMsg, $input, $output, $errno, $printStack);
    }

    /**
     * @param $errno
     * @param bool $showOut
     * @param null $input
     * @param null $output
     * @param null $msg
     * @param bool $throwException
     * @param bool $printStack
     * @throws Exception
     */
    protected function printWarning($errno, $showOut = false, $input = null, $output = null, $msg = null, $throwException = false, $printStack = true){
        $newMsg = $this->scriptTitle . ': ' . $msg;

        if ($showOut){
            echo $newMsg . "\n";
        }

        $this->objLog->warning($errno, $input, $output, $newMsg, $throwException, null, $printStack);
    }

    // ----------------------------- private functions ----------------------------------------
    /**
     * @return array
     */
    private function getCommands(){
        $commands = array_merge($this->defaultCommands, $this->commands);
        return $commands;
    }
}
