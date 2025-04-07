<?php
namespace Puge2016\MoonshotAiSdk;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use JsonException;
use Puge2016\MoonshotAiSdk\Exceptions\ErrorHandler;
use Puge2016\MoonshotAiSdk\Exceptions\MoonshotException;

/**
 * MoonshotAI SDK - 用于与 Moonshot AI API 交互的PHP客户端
 * 
 * 此SDK支持以下功能：
 * - 文本对话（Chat Completion）
 * - 图像理解（Vision）
 * - 文件处理和分析
 * - 模型列表查询
 * - Token计算
 * - 账户余额查询
 */
class MoonshotAI
{   
    /**
     * 聊天历史记录
     * @var array
     */
    public array $history = [
        [
            'role' => 'system',
            'content' => '你是 Kimi，由 Moonshot AI 提供的人工智能助手，你更擅长中文和英文的对话。你会为用户提供安全，有帮助，准确的回答。同时，你会拒绝一切涉及恐怖主义，种族歧视，黄色暴力等问题的回答。Moonshot AI 为专有名词，不可翻译成其他语言。',
        ]
    ];

    /**
     * 是否截断响应内容
     * @var bool
     */
    public bool $partial = false;
    
    /**
     * 消息内容缓存
     * @var string
     */
    public string $messageContent = '';
    
    /**
     * JSON模式下的消息内容缓存，用于处理长回复
     * @var string
     */
    public string $jsonPartialContent = '';
    
    /**
     * 对话标签，用于标识会话
     * @var string
     */
    public string $dialogueTag = '';
    
    /**
     * 使用的模型类型
     * @var string
     */
    public string $modelType = '';
    
    /**
     * API基础URL
     * @var string
     */
    public string $baseUrl = 'https://api.moonshot.cn/v1';
    
    /**
     * API密钥
     * @var string
     */
    public string $apiKey = '';

    /**
     * 重试配置
     * @var array
     */
    protected array $retryConfig = [
        'maxRetries' => 6, 
        'initialDelay' => 3
    ];

    /**
     * API密钥映射，用于管理多个API密钥
     * @var array
     */
    protected array $aiKeyMap = [];
    
    /**
     * 缓存处理器
     * @var callable|null
     */
    protected $cacheHandler = null;
    
    /**
     * 日志处理器
     * @var callable|null
     */
    protected $logHandler = null;

    /**
     * 最后使用的选项参数，用于递归调用
     * @var array
     */
    protected array $lastOptions = [];

    /**
     * 是否正在处理流式输出
     * 
     * @var bool
     */
    private bool $streamActive = false;
    
    /**
     * 流式输出缓冲区
     * 
     * @var string
     */
    private $streamBuffer = '';
    
    /**
     * 流式输出进度
     * 
     * @var int
     */
    private int $streamProgress = 0;

    // 添加一个属性来跟踪最后的finish_reason，用于检测是否因长度限制被截断
    private $lastFinishReason = null;

    // 添加一个属性来存储部分模式的数据
    private $partialModeData = [];

    /**
     * MoonshotAI 构造函数
     * @param string $dialogueTag 对话标签，用于标识会话
     * @param string $modelType 模型类型
     * @param string $apiKey API密钥
     */
    public function __construct(string $dialogueTag = '', string $modelType = 'moonshot-v1-8k', string $apiKey = '')
    {
        $this->dialogueTag = empty($dialogueTag) ? uniqid('MoonshotAI_' . microtime(true), true) : $dialogueTag;
        $this->modelType = $modelType;
        $this->apiKey = $apiKey;
    }

    /**
     * 设置API密钥
     * @param string $apiKey API密钥
     * @return $this
     */
    public function setApiKey(string $apiKey): self
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    /**
     * 设置模型类型
     * @param string $modelType 模型类型（如 moonshot-v1-8k, moonshot-v1-32k, moonshot-v1-128k, moonshot-v1-8k-vision-preview）
     * @return $this
     */
    public function setModelType(string $modelType): self
    {
        $this->modelType = $modelType;
        return $this;
    }

    /**
     * 设置多个API密钥映射
     * @param array $keyMap 键名为标识，键值为API密钥
     * @return $this
     */
    public function setApiKeyMap(array $keyMap): static
    {
        $this->aiKeyMap = $keyMap;
        return $this;
    }

    /**
     * 设置缓存处理器
     * @param callable $handler 缓存处理器，需要实现get($key)和set($key, $value, $ttl)方法
     * @return $this
     */
    public function setCacheHandler(callable $handler): static
    {
        $this->cacheHandler = $handler;
        return $this;
    }

    /**
     * 设置日志处理器
     * @param callable $handler 日志处理器，接收($message, $level)参数
     * @return $this
     */
    public function setLogHandler(callable $handler): static
    {
        $this->logHandler = $handler;
        return $this;
    }

    /**
     * 设置重试配置
     * @param int  $maxRetries   最大重试次数
     * @param int  $initialDelay 初始延迟秒数
     * @param int  $maxDelay     最大延迟秒数，默认60秒
     * @param bool $useJitter    是否使用随机抖动，默认true
     * @return $this
     */
    public function setRetryConfig(int $maxRetries, int $initialDelay = 2, int $maxDelay = 60, bool $useJitter = true): static
    {
        $this->retryConfig['maxRetries'] = max(0, intval($maxRetries));
        $this->retryConfig['initialDelay'] = max(1, intval($initialDelay));
        $this->retryConfig['maxDelay'] = max($initialDelay, intval($maxDelay));
        $this->retryConfig['useJitter'] = (bool)$useJitter;
        return $this;
    }

    /**
     * 记录日志
     * @param mixed $message 日志消息
     * @param int $level 日志级别 (0:调试, 1:信息, 2:警告, 3:错误)
     */
    protected function log(mixed $message, int $level = 0): void
    {
        // 增加时间戳
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $this->getLogLevelName($level),
            'message' => $message
        ];
        
        if (is_callable($this->logHandler)) {
            call_user_func($this->logHandler, $logData, $level);
        } else {
            // 没有设置日志处理器，对于错误级别的消息输出到错误日志
            if ($level >= 2 && is_string($message)) {
                error_log("[MoonshotAI] {$logData['level']}: {$message}");
            }
        }
    }
    
    /**
     * 获取日志级别名称
     * 
     * @param int $level 日志级别
     * @return string 级别名称
     */
    private function getLogLevelName(int $level): string
    {
        return match ($level) {
            0 => 'DEBUG',
            1 => 'INFO',
            2 => 'WARNING',
            3 => 'ERROR',
            default => 'UNKNOWN',
        };
    }

    /**
     * 获取缓存
     * @param string $key 缓存键
     * @return mixed|null
     */
    protected function getCache(string $key): mixed
    {
        if (is_callable($this->cacheHandler)) {
            try {
                return call_user_func([$this->cacheHandler, 'get'], $key);
            } catch (Exception $e) {
                $this->log(['缓存获取失败', $e->getMessage()], 2);
                return null;
            }
        }
        return null;
    }

    /**
     * 设置缓存
     * @param string $key   缓存键
     * @param mixed  $value 缓存值
     * @param int    $ttl   有效期（秒）
     * @return bool
     */
    protected function setCache(string $key, mixed $value, int $ttl = 300): bool
    {
        if (is_callable($this->cacheHandler)) {
            try {
                return call_user_func([$this->cacheHandler, 'set'], $key, $value, $ttl);
            } catch (Exception $e) {
                $this->log(['缓存设置失败', $e->getMessage()], 2);
                return false;
            }
        }
        return false;
    }

    /**
     * 清理历史记录
     */
    public function cleanHistory()
    {
        $messages = $this->history ; 
        $this->history = [] ; 
        foreach ($messages as $message) {
            if (isset($message['role']) && $message['role'] === 'system') {
                $this->history[] = $message ; 
            }
        }
    }

    /**
     * 清理Moonshot API上的文件
     * @return bool 是否清理成功
     * @throws MoonshotException
     */
    public function cleanMoonshot(): bool
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            $files = $this->listFiles();
            foreach ($files as $file) {
                if (isset($file['id'])) {
                    $this->deleteFile($file['id']);
                }
            }
            return true;
        } catch (Exception $e) {
            $this->log(['清理文件失败', $e->getMessage()], 2);
            throw ErrorHandler::createError("清理文件失败: " . $e->getMessage(), 0, 'files_cleanup_error');
        }
    }

    /**
     * 获取文件列表.
     *
     * @return array 文件列表
     * @throws MoonshotException
     */
    public function listFiles(): array
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            $response = $client->get("{$this->baseUrl}/files", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
            ]);

            $responseBody = json_decode($response->getBody(), true);
            return $responseBody['data'] ?? [];
        } catch (GuzzleException $e) {
            $this->log(['获取文件列表失败', $e->getMessage()], 2);
            throw ErrorHandler::handleApiError($e);
        } catch (Exception $e) {
            $this->log(['获取文件列表失败', $e->getMessage()], 2);
            throw ErrorHandler::createError("获取文件列表失败: " . $e->getMessage(), 0, 'files_list_error');
        }
    }

    /**
     * 获取指定文件的信息.
     *
     * @param string $fileId 文件ID
     * @return array 文件信息
     * @throws MoonshotException
     */
    public function retrieveFile(string $fileId): array
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            if (empty($fileId)) {
                throw new Exception("File ID is required");
            }
            
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            $response = $client->get("{$this->baseUrl}/files/{$fileId}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
            ]);

            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            $this->log(['获取文件信息失败', $e->getMessage()], 2);
            throw ErrorHandler::handleApiError($e);
        } catch (Exception $e) {
            $this->log(['获取文件信息失败', $e->getMessage()], 2);
            throw ErrorHandler::createError("获取文件信息失败: " . $e->getMessage(), 0, 'file_retrieve_error');
        }
    }

    /**
     * 获取指定文件的内容.
     *
     * @param string $fileId 文件ID
     * @return string 文件内容
     * @throws MoonshotException
     */
    public function retrieveFileContent(string $fileId): string
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            if (empty($fileId)) {
                throw new Exception("File ID is required");
            }
            
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            $response = $client->get("{$this->baseUrl}/files/{$fileId}/content", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
            ]);

            $responseBody = json_decode($response->getBody(), true);
            
            // 尝试不同的响应格式
            if (isset($responseBody['content'])) {
                return $responseBody['content'];  // 标准JSON返回
            }
            
            if (isset($responseBody['text'])) {
                return $responseBody['text'];  // 有些API返回 text 字段
            }
            
            // 如果没有结构化返回，直接使用完整响应
            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            $this->log(['获取文件内容失败', $e->getMessage()], 2);
            throw ErrorHandler::handleApiError($e);
        } catch (Exception $e) {
            $this->log(['获取文件内容失败', $e->getMessage()], 2);
            throw ErrorHandler::createError("获取文件内容失败: " . $e->getMessage(), 0, 'file_content_error');
        }
    }

    /**
     * 删除指定文件
     *
     * @param string $fileId 文件ID
     * @return mixed|bool 删除是否成功
     * @throws MoonshotException
     */
    public function deleteFile(string $fileId)
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            if (empty($fileId)) {
                throw new Exception("File ID is required");
            }
            
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            $response = $client->delete("{$this->baseUrl}/files/{$fileId}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                return $response->getBody()->getContents();
            }

            return false;
        } catch (GuzzleException $e) {
            $this->log(['删除文件失败', $e->getMessage()], 2);
            throw ErrorHandler::handleApiError($e);
        } catch (Exception $e) {
            $this->log(['删除文件失败', $e->getMessage()], 2);
            throw ErrorHandler::createError("删除文件失败: " . $e->getMessage(), 0, 'file_delete_error');
        }
    }

    /**
     * 上传文件.
     *
     * @param string $filePath 文件路径
     * @param string $purpose 文件用途，默认为文本提取
     * @return array 上传结果
     * @throws MoonshotException
     */
    public function uploadFile(string $filePath, string $purpose = 'file-extract'): array
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            if (!file_exists($filePath)) {
                throw new Exception("File not found: {$filePath}");
            }
            
            // 获取文件MIME类型
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            
            // 打开文件流
            $fileStream = fopen($filePath, 'r');
            if ($fileStream === false) {
                throw new Exception("Cannot open file: {$filePath}");
            }
            
            $client = new Client([
                'timeout' => 60, // 上传可能需要更长时间
                'connect_timeout' => 10
            ]);
            
            $fileName = basename($filePath);
            
            $response = $client->post("{$this->baseUrl}/files", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                ],
                'multipart' => [
                    [
                        'name' => 'purpose',
                        'contents' => $purpose
                    ],
                    [
                        'name' => 'file',
                        'contents' => $fileStream,
                        'filename' => $fileName,
                        'headers' => [
                            'Content-Type' => $mimeType
                        ]
                    ]
                ]
            ]);

            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            $this->log(['文件上传失败', $e->getMessage()], 2);
            throw ErrorHandler::handleApiError($e);
        } catch (Exception $e) {
            $this->log(['文件上传失败', $e->getMessage()], 2);
            throw ErrorHandler::createError("文件上传失败: " . $e->getMessage(), 0, 'file_upload_error');
        }
    }

    /**
     * 与Moonshot AI进行对话
     * 
     * @param string $query 用户查询内容
     * @param array $options 额外的选项参数
     * @return string 响应内容
     * @throws MoonshotException
     */
    public function moonshot(string $query, array $options = []): string
    {
        try {
            // 验证API密钥
            $this->validateApiKey();
            
            // 处理查询内容
            $query = $this->sanitizeQuery($query);
            
            // 保存选项参数，用于可能的递归调用
            $this->lastOptions = $options;
            
            // 验证参数
            $this->validateMoonshotOptions($options);
            
            // 准备请求参数
            $requestOptions = $this->prepareRequestOptions($query, $options);
            
            // 发送请求并获取响应
            $response = $this->sendRequest($this->baseUrl . '/chat/completions', $requestOptions);

            // 处理响应内容
            $isStream = isset($options['stream']) && $options['stream'] === true;
            $responseContent = $this->processResponse($response, $isStream);
            
            // 处理流式响应
            if ($isStream) {
                return $responseContent['content'];
            }
            
            if ($this->partial === false) {
                // 更新历史记录
                $this->updateHistory($responseContent);
                // 格式化JSON字符串
                return $this->formatJsonString($responseContent);
            } else {
                return '';
            }
        } catch (GuzzleException $e) {
            $this->log(['对话请求失败', $e->getMessage()], 2);
            $this->log($e->getTraceAsString(), 1);
            throw ErrorHandler::handleApiError($e);
        } catch (Exception $e) {
            $this->log(['对话请求失败', $e->getMessage()], 2);
            $this->log($e->getTraceAsString(), 1);
            throw ErrorHandler::createError("对话请求失败: " . $e->getMessage(), 0, 'chat_completion_error');
        }
    }

    /**
     * 验证API密钥是否设置
     * 
     * @throws MoonshotException 如果API密钥未设置
     */
    private function validateApiKey(): void
    {
        if (empty($this->apiKey)) {
            throw ErrorHandler::createError("API 密钥未设置，请先调用 setApiKey 方法设置密钥", 401, 'api_key_not_set');
        }
    }

    /**
     * 验证Moonshot对话选项
     * 
     * @param array $options 选项参数
     * @return void
     */
    private function validateMoonshotOptions(array $options): void
    {
        // 验证temperature和n参数
        if (isset($options['temperature']) && $options['temperature'] == 0 && 
            isset($options['n']) && $options['n'] > 1) {
            throw new Exception("当temperature=0时，n值必须为1");
        }
        
        // 确保temperature在合法范围
        if (isset($options['temperature']) && $options['temperature'] > 1) {
            $this->log(['警告: temperature值超出范围[0,1]，已自动调整为1', $options['temperature']], 2);
            $options['temperature'] = 1;
        }
    }

    /**
     * 使用工具调用功能发送请求到Moonshot API
     * 
     * @param string $query   用户查询内容
     * @param array  $tools   可用工具列表
     * @param array  $options 额外的选项参数
     * @return array 包含响应内容和工具调用信息的数组
     * @throws Exception|GuzzleException
     */
    public function toolCall(string $query = '', array $tools = [], array $options = []): array
    {
        try {
            // 检查API密钥
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            // 处理查询内容
            $query = $this->sanitizeQuery($query);
            
            // 添加用户消息到历史记录 - 仅当查询不为空时
            if (!empty($query)) {
                $this->history[] = [
                    'role' => 'user',
                    'content' => $query,
                ];
            }
            
            // 准备请求参数
            $defaultParams = [
                'model' => $this->modelType,
                'messages' => $this->history,
                'temperature' => 0.3,
                'tools' => $tools,
            ];
            
            // 合并用户提供的选项
            $requestParams = array_merge($defaultParams, $options);
            
            $requestOptions = [
                RequestOptions::JSON => $requestParams,
                RequestOptions::HEADERS => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'tag' => $this->dialogueTag,
                ]
            ];
            
            // 发送请求并获取响应
            $response = $this->sendRequest($this->baseUrl . '/chat/completions', $requestOptions);
            $responseBody = $response->getBody()->getContents();
            
            // 检查是否有错误
            $this->checkResponseError($responseBody);
            
            // 解析响应
            $resArr = json_decode($responseBody, true);
            $responseMessage = $resArr['choices'][0]['message'] ?? [];
            
            // 更新历史记录
            $this->history[] = $responseMessage;
            
            // 处理tool_choice参数
            if (isset($options['tool_choice'])) {
                // 验证tool_choice的有效值
                $validChoices = ['auto', 'none', null];
                if (!in_array($options['tool_choice'], $validChoices)) {
                    $this->log(['警告: 不支持的tool_choice值', $options['tool_choice']], 2);
                    // 如果是"required"，给出专门提示
                    if ($options['tool_choice'] === 'required') {
                        $this->log('Moonshot API目前不支持tool_choice=required，将使用auto代替', 2);
                    }
                    $options['tool_choice'] = 'auto'; // 使用默认值代替
                }
            }
            
            return $responseMessage;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 1);
            $this->log($e->getTraceAsString(), 1);
            throw $e;
        }
    }
    
    /**
     * 处理工具调用的完整流程
     * 
     * @param string   $query        用户查询内容
     * @param array    $tools        可用工具列表
     * @param callable $toolExecutor 工具执行函数，接收工具调用参数并返回执行结果
     * @param array    $options      额外的选项参数
     * @return array 最终对话结果
     * @throws Exception
     */
    public function executeToolCall(string $query, array $tools, callable $toolExecutor, array $options = []): array
    {
        // 第一次调用，获取模型的工具调用请求
        $response = $this->toolCall($query, $tools, $options);
        
        // 如果有工具调用
        if (!empty($response['tool_calls'])) {
            $toolCalls = $response['tool_calls'];
            
            // 执行每个工具并收集结果
            foreach ($toolCalls as $toolCall) {
                $function = $toolCall['function'];
                $toolName = $function['name'];
                $toolArguments = json_decode($function['arguments'], true);
                
                // 使用传入的执行器执行工具
                $result = call_user_func($toolExecutor, $toolName, $toolArguments);
                
                // 添加工具执行结果到对话历史
                $this->history[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'name' => $toolName,
                    'content' => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE)
                ];
            }
            
            // 再次调用获取最终响应
            $finalResponse = $this->toolCall('', [], $options);
            return $finalResponse;
        }
        
        return $response;
    }

    /**
     * 创建Vision模型消息
     * 将图片文件或base64编码的图片数据转换为Vision模型所需的消息格式
     * 
     * @param string $imagePathOrBase64 图片文件路径或已编码的base64字符串 
     * @param string $prompt            关于图片的问题或指令
     * @return array 格式化的消息数组，可直接用于vision方法
     * @throws Exception 如果文件读取失败
     */
    public function createVisionMessage(string $imagePathOrBase64, string $prompt): array
    {
        // 检查是否为base64格式
        $isBase64 = (str_starts_with($imagePathOrBase64, 'data:image/'));
        
        // 如果不是base64，则尝试读取文件并转换
        if (!$isBase64) {
            if (!file_exists($imagePathOrBase64)) {
                throw new Exception("图片文件不存在: {$imagePathOrBase64}");
            }
            
            // 读取文件内容
            $imageData = file_get_contents($imagePathOrBase64);
            if ($imageData === false) {
                throw new Exception("无法读取图片文件: {$imagePathOrBase64}");
            }
            
            // 获取文件扩展名
            $extension = strtolower(pathinfo($imagePathOrBase64, PATHINFO_EXTENSION));
            // 处理常见图片格式
            if ($extension == 'jpg' || $extension == 'jpeg') {
                $mimeType = 'image/jpeg';
            } else if ($extension == 'png') {
                $mimeType = 'image/png';
            } else if ($extension == 'gif') {
                $mimeType = 'image/gif';
            } else if ($extension == 'webp') {
                $mimeType = 'image/webp';
            } else {
                $mimeType = 'image/' . $extension;
            }
            
            // 转换为base64
            $base64Image = base64_encode($imageData);
            $imageUrl = "data:{$mimeType};base64,{$base64Image}";
        } else {
            // 已经是base64格式
            $imageUrl = $imagePathOrBase64;
        }
        
        // 构建Vision消息格式
        $message = [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => $imageUrl
                    ]
                ],
                [
                    'type' => 'text',
                    'text' => $prompt
                ]
            ]
        ];
        
        return $message;
    }

    /**
     * 发送带图像的请求到Moonshot API (Vision)
     * @param mixed $messages 消息数组或createVisionMessage创建的单个消息
     * @param array $options 额外的选项参数
     * @return string 响应内容
     * @throws Exception
     */
    public function vision(mixed $messages, array $options = []): string
    {
        try {
            // 检查API密钥
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }

            // 检查模型是否为Vision模型
            $modelType = $options['model'] ?? $this->modelType;
            if (strpos($modelType, 'vision') === false) {
                // 如果不是Vision模型，自动切换到Vision模型
                $modelType = 'moonshot-v1-8k-vision-preview';
                $this->log(["自动切换到Vision模型: {$modelType}"], 2);
            }

            // 如果传入的是单个消息(通过createVisionMessage创建)，转换为数组格式
            if (isset($messages['role']) && $messages['role'] === 'user') {
                $messages = [$messages];
            }
            
            // 确保所有消息格式正确，特别是content字段
            foreach ($messages as $index => $message) {
                // 检查是否需要添加系统消息
                if ($index === 0 && $message['role'] === 'user' && empty($this->history)) {
                    // 如果历史为空且第一条是用户消息，添加默认系统消息
                    array_unshift($messages, [
                        'role' => 'system',
                        'content' => '你是一个视觉AI助手，能够理解和分析图片内容。'
                    ]);
                    break;
                }
            }

            // 准备请求参数
            $options['model'] = $modelType;
            $requestParams = array_merge([
                'model' => $modelType,
                'messages' => array_merge($this->history, $messages),
                'temperature' => 0.3,
                'stream' => false,
            ], $options);

            $requestOptions = [
                RequestOptions::JSON => $requestParams,
                RequestOptions::HEADERS => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'tag' => $this->dialogueTag,
                ]
            ];

            // 发送请求并获取响应
            $response = $this->sendRequest($this->baseUrl . '/chat/completions', $requestOptions);

            // 处理响应内容
            $responseContent = $this->processResponse($response, $options['stream'] ?? false);
            
            if ($this->partial === false) {
                // 更新历史记录
                $this->updateHistory($responseContent);
                // 格式化JSON字符串
                return $this->formatJsonString($responseContent);
            } else {
                return '';
            }
        } catch (Exception $e) {
            $this->log($e->getMessage(), 1);
            $this->log($e->getTraceAsString(), 1);
            throw $e;
        }
    }

    /**
     * 创建包含多张图片的Vision消息
     * 
     * @param array  $imagePaths 图片文件路径数组
     * @param string $prompt     关于图片的问题或指令
     * @return array 格式化的消息数组，可直接用于vision方法
     * @throws Exception 如果文件读取失败
     */
    public function createMultiImageVisionMessage(array $imagePaths, string $prompt): array
    {
        if (empty($imagePaths)) {
            throw new Exception("至少需要提供一张图片");
        }
        
        $content = [];
        
        // 处理每张图片
        foreach ($imagePaths as $imagePath) {
            // 检查是否为base64格式
            $isBase64 = (strpos($imagePath, 'data:image/') === 0);
            
            // 如果不是base64，则尝试读取文件并转换
            if (!$isBase64) {
                if (!file_exists($imagePath)) {
                    throw new Exception("图片文件不存在: {$imagePath}");
                }
                
                // 读取文件内容
                $imageData = file_get_contents($imagePath);
                if ($imageData === false) {
                    throw new Exception("无法读取图片文件: {$imagePath}");
                }
                
                // 获取文件扩展名和MIME类型
                $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
                if ($extension == 'jpg' || $extension == 'jpeg') {
                    $mimeType = 'image/jpeg';
                } else if ($extension == 'png') {
                    $mimeType = 'image/png';
                } else {
                    $mimeType = 'image/' . $extension;
                }
                
                // 转换为base64
                $base64Image = base64_encode($imageData);
                $imageUrl = "data:{$mimeType};base64,{$base64Image}";
            } else {
                // 已经是base64格式
                $imageUrl = $imagePath;
            }
            
            // 添加到内容数组
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $imageUrl
                ]
            ];
        }
        
        // 添加文本提示
        $content[] = [
            'type' => 'text',
            'text' => $prompt
        ];
        
        // 构建完整消息
        return [
            'role' => 'user',
            'content' => $content
        ];
    }

    /**
     * 简化的视觉识别方法，一步完成图片分析 . 
     * 
     * @param array|string $imagePathOrPaths 单个图片路径或图片路径数组
     * @param string       $prompt           关于图片的问题或指令
     * @param array        $options          额外的选项参数
     * @return string 模型回答
     * @throws Exception
     */
    public function analyzeImage(array|string $imagePathOrPaths, string $prompt, array $options = []): string
    {
        // 检查是否为多图
        if (is_array($imagePathOrPaths)) {
            $message = $this->createMultiImageVisionMessage($imagePathOrPaths, $prompt);
        } else {
            $message = $this->createVisionMessage($imagePathOrPaths, $prompt);
        }
        
        // 确保使用Vision模型
        if (!isset($options['model']) || strpos($options['model'], 'vision') === false) {
            $options['model'] = 'moonshot-v1-8k-vision-preview';
        }
        
        // 调用vision方法获取结果
        return $this->vision($message, $options);
    }

    /**
     * 获取支持的模型列表
     * @throws Exception|GuzzleException
     */
    public function listModels()
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }

            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);

            $response = $client->get("{$this->baseUrl}/models", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
            ]);
 
            return $response->getBody()->getContents();
        } catch (Exception $e) {
            $this->log(['获取模型列表失败', $e->getMessage()], 1);
            throw $e;
        }
    }

    /**
     * 格式化JSON字符串
     * @param string $str 要格式化的字符串
     * @return string 格式化后的字符串
     */
    private function formatJsonString(string $str): string
    {
        $replacements = [
            '{\n' => '{',
            '\n}' => '}',
            '{  "' => '{"',
            '" }' => '"}',
            '<br/>' => '\n',
            '\<br\>' => '\n',
            '\n\n' => '\n'
        ];
        
        foreach ($replacements as $search => $replace) {
            $str = str_replace((array)$search, $replace, $str);
        }
        
        return $str;
    }

    /**
     * 更新历史记录
     * @param string $content 内容
     */
    private function updateHistory(string $content): void
    {
        $this->history[] = [
            'role' => 'assistant',
            'content' => $content,
        ];
    }

    /**
     * 更新历史记录
     * @param array $messages 消息列表
     * @return $this
     */
    public function updateHistoryWithMessages(array $messages): static
    {
        $this->history = array_merge($messages, $this->history);
        return $this;
    }

    /**
     * 清除历史记录
     * @return $this
     */
    public function clearHistory(): static
    {
        $systemMessage = null;
        
        // 保留系统消息（如果有）
        foreach ($this->history as $message) {
            if ($message['role'] === 'system') {
                $systemMessage = $message;
                break;
            }
        }

        $this->history = [];

        // 如果找到了系统消息，将其添加回历史记录
        if ($systemMessage) {
            $this->history[] = $systemMessage;
        }
        return $this;
    }

    /**
     * 处理API响应
     * @param object $response 响应对象
     * @param bool   $isStream 是否为流式响应
     * @return string|array 处理后的内容
     * @throws JsonException|Exception
     */
    private function processResponse(object $response, bool $isStream = false): array|string
    {
        $responseBody = $response->getBody()->getContents();
        
        // 检查是否有错误
        $this->checkResponseError($responseBody);

        if ($isStream) {
            $result = $this->processStreamResponse($responseBody);
            // 保存finish_reason
            $this->lastFinishReason = $result['finish_reason'] ?? null;
            return $result;
        }

        $resArr = json_decode($responseBody, true);
        if (!isset($resArr['choices'][0]['message']['content'])) {
            $this->log(['无效的响应格式', $resArr], 3);
            return '';
        }
        
        $content = $resArr['choices'][0]['message']['content'];
        $finishReason = $resArr['choices'][0]['finish_reason'] ?? '';
        
        // 保存finish_reason，用于检测是否因长度限制而被截断
        $this->lastFinishReason = $finishReason;

        if ($finishReason === 'stop') {
            $this->partial = false;
            if (empty($this->messageContent)) {
                return $content;
            } else {
                $this->messageContent .= $content;
                $messageContent = $this->messageContent; 
                $this->messageContent = ''; // 清空消息内容 
                return $messageContent;
            }
        }

        // 增强对partial模式的处理
        if ($this->partial) {
            // 查找历史记录中最后一个partial标记的消息
            $lastPartialIndex = null;
            foreach ($this->history as $index => $message) {
                if (isset($message['partial']) && $message['partial'] === true) {
                    $lastPartialIndex = $index;
                }
            }
            
            if ($lastPartialIndex !== null) {
                // 用新内容替换或更新partial消息
                $this->history[$lastPartialIndex]['content'] .= $content;
                $this->history[$lastPartialIndex]['partial'] = false;
            }
        }

        // 内容被截断了，需要继续请求 
        if ($finishReason === 'length') {
            $this->partial = true; 
            $this->messageContent .= $content;
            
            // 使用保存的选项参数进行递归调用，但移除max_tokens限制，以获取完整响应
            $continuationOptions = $this->lastOptions;
            unset($continuationOptions['max_tokens']); // 移除token限制以获取完整响应
            
            // 防止递归调用次数过多
            static $recursionCount = 0;
            if (++$recursionCount > 5) {
                $this->log(['递归调用次数过多，提前结束', $recursionCount], 2);
                $recursionCount = 0;
                $this->partial = false;
                $messageContent = $this->messageContent;
                $this->messageContent = '';
                return $messageContent;
            }
            
            $messageContent = $this->moonshot($content, $continuationOptions);
            $recursionCount = 0; // 重置递归计数
            return $messageContent; 
        }
        
        return $content;
    }

    /**
     * 处理流式响应
     * @param string $responseBody 响应内容
     * @return array 处理后的内容和元数据
     */
    private function processStreamResponse(string $responseBody): array
    {
        $lines = explode(PHP_EOL, $responseBody);
        $lines = array_filter($lines);
        
        $allContent = ''; 
        $usage = [];
        $finishReason = '';
        $rawChunks = [];
        $dataBuffer = '';
        $currentChunks = [];
        
        foreach ($lines as $line) {
            // 如果是空行，表示一个数据块结束，解析之前收集的数据
            if (trim($line) === '') {
                if (!empty($dataBuffer)) {
                    // 处理完整的数据块
                    $this->processDataChunk($dataBuffer, $allContent, $usage, $finishReason, $rawChunks);
                    $dataBuffer = '';
                }
                continue;
            }
            
            // 如果是新的数据块开始
            if (str_starts_with($line, 'data: ')) {
                // 如果已经有数据，先处理之前的数据
                if (!empty($dataBuffer)) {
                    $this->processDataChunk($dataBuffer, $allContent, $usage, $finishReason, $rawChunks);
                    $dataBuffer = '';
                }
                
                // 获取新数据块
                $dataBuffer = substr($line, 6); // 去除 'data: ' 前缀
                
                // 检查是否是结束标记
                if ($dataBuffer === '[DONE]') {
                    break; // 数据传输结束
                }
            } else {
                // 当前行是数据块的一部分，追加到buffer
                $dataBuffer .= "\n" . $line;
            }
        }
        
        // 处理最后一个可能的数据块
        if (!empty($dataBuffer) && $dataBuffer !== '[DONE]') {
            $this->processDataChunk($dataBuffer, $allContent, $usage, $finishReason, $rawChunks);
        }
        
        return [
            'content' => $allContent,
            'usage' => $usage,
            'finish_reason' => $finishReason,
            'raw_chunks' => false ? $rawChunks : null
        ];
    }

    /**
     * 处理单个数据块
     * @param string  $data         数据块内容
     * @param string &$allContent   引用传递的所有内容
     * @param array &$usage         引用传递的用量信息
     * @param string &$finishReason 引用传递的完成原因
     * @param array &$rawChunks     引用传递的原始数据块
     */
    private function processDataChunk(string $data, string &$allContent, array &$usage, string &$finishReason, array &$rawChunks): void
    {
        try {
            $chunk = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log(['JSON解析错误', $data], 2);
                return;
            }
            
            if (false) {
                $rawChunks[] = $chunk;
            }
            
            // 处理每个选择项
            foreach ($chunk['choices'] ?? [] as $choice) {
                // 处理delta内容
                if (isset($choice['delta']['content']) && !empty($choice['delta']['content'])) {
                    $allContent .= $choice['delta']['content'];
                }
                
                // 检查是否有完成原因
                if (isset($choice['finish_reason']) && !empty($choice['finish_reason'])) {
                    $finishReason = $choice['finish_reason'];
                }
                
                // 如果有用量信息，保存它
                if (isset($choice['usage'])) {
                    $usage = $choice['usage'];
                }
            }
            
            // 如果主chunk中也有usage信息
            if (isset($chunk['usage'])) {
                $usage = $chunk['usage'];
            }
        } catch (Exception $e) {
            $this->log(['处理流式数据块失败', $e->getMessage()], 2);
        }
    }

    /**
     * 检查响应中是否有错误
     * @param string $responseBody 响应内容
     * @throws MoonshotException 当响应包含错误时抛出
     */
    private function checkResponseError(string $responseBody): void
    {
        try {
            $data = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
            
            // 检查是否包含错误
            if (isset($data['error'])) {
                $errorType = $data['error']['type'] ?? 'api_error';
                $errorMessage = $data['error']['message'] ?? '未知错误';
                $errorCode = $data['error']['code'] ?? 400;
                
                throw new MoonshotException($errorMessage, $errorCode, $errorType, $data);
            }
        } catch (JsonException $e) {
            // 响应不是有效的 JSON
            if (stripos($responseBody, 'error') !== false && strlen($responseBody) < 1000) {
                throw ErrorHandler::createError("响应格式错误: " . $responseBody, 0, 'invalid_response');
            }
        }
    }

    /**
     * 发送请求到API并处理可能的错误
     * 
     * @param string $apiUrl API地址
     * @param array $requestOptions 请求选项
     * @return object 响应对象
     * @throws MoonshotException 请求出错时抛出
     */
    private function sendRequest(string $apiUrl, array $requestOptions): object
    {
        $client = new Client();
        $maxRetries = $this->retryConfig['maxRetries'];
        $initialDelay = $this->retryConfig['initialDelay'];
        $attempt = 0;
        
        while ($attempt < $maxRetries) {
            try {
                return $client->request('POST', $apiUrl, $requestOptions);
            } catch (GuzzleException $e) {
                $attempt++;
                
                // 如果是最后一次尝试，或者是不应该重试的错误，直接抛出
                if ($attempt >= $maxRetries || !$this->shouldRetry($e)) {
                    throw ErrorHandler::handleApiError($e);
                }
                
                // 计算指数退避时间
                $delay = $initialDelay * (2 ** ($attempt - 1));
                // 添加抖动，避免多个客户端同时重试
                $jitter = $delay * 0.2 * (mt_rand(0, 100) / 100);
                $delay = $delay + $jitter;
                
                // 记录日志
                $this->log(["请求失败，准备重试 (尝试 {$attempt}/{$maxRetries}), 等待 {$delay} 秒", $e->getMessage()], 2);
                
                // 等待后重试
                sleep((int)$delay);
            }
        }
        
        // 如果所有重试都失败了（理论上不会执行到这里）
        throw ErrorHandler::createError("最大重试次数 {$maxRetries} 后仍然请求失败", 0, 'max_retries_exceeded');
    }

    /**
     * 判断是否应该重试请求
     * 
     * @param GuzzleException $e 异常
     * @return bool 是否重试
     */
    private function shouldRetry(GuzzleException $e): bool
    {
        // 服务器错误或请求超时应该重试
        if ($e instanceof \GuzzleHttp\Exception\ServerException) {
            return true;
        }
        
        // 连接错误应该重试
        if ($e instanceof \GuzzleHttp\Exception\ConnectException) {
            return true;
        }
        
        // 429 错误（速率限制）应该重试
        if ($e instanceof ClientException && $e->getResponse()->getStatusCode() === 429) {
            return true;
        }
        
        // 其他客户端错误不应该重试
        return false;
    }

    /**
     * 准备请求选项
     * @param string $query   查询内容
     * @param array  $options 额外的选项参数
     * @return array 请求选项
     */
    private function prepareRequestOptions(string $query, array $options = []): array
    {
        if ($this->partial) {
            $this->history[] = [
                'role' => 'assistant',
                'content' => $query,
                'partial' => $this->partial,
            ];
        } else {
            $this->history[] = [
                'role' => 'user',
                'content' => $query,
            ];
        }

        if (isset($options['json_format']) && $options['json_format']['role'] === 'system') {
            $this->history[] = $options['json_format'];
        }

        // 默认参数
        $defaultParams = [
            'model' => $this->modelType,
            'messages' => $this->history,
            'temperature' => 0.3,
            'stream' => false,
        ];

        // 合并用户提供的选项
        $requestParams = array_merge($defaultParams, $options);

        return [
            RequestOptions::JSON => $requestParams,
            RequestOptions::HEADERS => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
                'tag' => $this->dialogueTag,
            ]
        ];
    }


    /**
     * 列出所有上下文缓存
     * 
     * @param array $options 列表选项(limit, order, after, before, metadata)
     * @return array 缓存列表
     * @throws Exception|GuzzleException
     */
    public function listCaches(array $options = []): array
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            // 构建URL参数
            $queryParams = [];
            foreach(['limit', 'order', 'after', 'before'] as $key) {
                if (isset($options[$key])) {
                    $queryParams[$key] = $options[$key];
                }
            }
            
            // 添加metadata查询参数
            if (isset($options['metadata']) && is_array($options['metadata'])) {
                foreach($options['metadata'] as $key => $value) {
                    $queryParams["metadata[{$key}]"] = $value;
                }
            }
            
            $url = "{$this->baseUrl}/caching";
            if (!empty($queryParams)) {
                $url .= '?' . http_build_query($queryParams);
            }
            
            $response = $client->get($url, [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            return $result['data'] ?? [];
        } catch (Exception $e) {
            $this->log(['获取缓存列表失败', $e->getMessage()], 2);
            throw $e;
        }
    }
    
    /**
     * 获取指定缓存的信息 .
     * 
     * @param string $cacheId 缓存ID
     * @return string 缓存信息
     * @throws Exception|GuzzleException
     */
    public function retrieveCache(string $cacheId)
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            if (empty($cacheId)) {
                throw new Exception("Cache ID is required");
            }
            
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            $response = $client->get("{$this->baseUrl}/caching/{$cacheId}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
            ]);
            
            return $response->getBody()->getContents();
        } catch (Exception $e) {
            $this->log(['获取缓存信息失败', $e->getMessage()], 2);
            throw $e;
        }
    }
    
    /**
     * 更新缓存信息. 
     * 
     * @param string $cacheId 缓存ID
     * @param array  $options 要更新的字段(metadata, ttl/expired_at)
     * @return array 更新后的缓存信息
     * @throws Exception|GuzzleException
     */
    public function updateCache(string $cacheId, array $options): array
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            if (empty($cacheId)) {
                throw new Exception("Cache ID is required");
            }
            
            if (empty($options)) {
                throw new Exception("No update options provided");
            }
            
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            // 构建请求体
            $requestBody = [];
            
            // 添加metadata
            if (isset($options['metadata'])) {
                $requestBody['metadata'] = $options['metadata'];
            }
            
            // 处理过期时间 (ttl和expired_at二选一)
            if (isset($options['ttl'])) {
                $requestBody['ttl'] = (int)$options['ttl'];
            } elseif (isset($options['expired_at'])) {
                $requestBody['expired_at'] = (int)$options['expired_at'];
            }
            
            $response = $client->put("{$this->baseUrl}/caching/{$cacheId}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestBody
            ]);
            
            return json_decode($response->getBody()->getContents(), true);
        } catch (Exception $e) {
            $this->log(['更新缓存失败', $e->getMessage()], 2);
            throw $e;
        }
    }
    
    /**
     * 删除指定缓存
     * 
     * @param string $cacheId 缓存ID
     * @return bool 删除是否成功
     * @throws Exception|GuzzleException
     */
    public function deleteCache(string $cacheId): bool
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            if (empty($cacheId)) {
                throw new Exception("Cache ID is required");
            }
            
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            $response = $client->delete("{$this->baseUrl}/caching/{$cacheId}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            return $result['deleted'] ?? false;
        } catch (Exception $e) {
            $this->log(['删除缓存失败', $e->getMessage()], 2);
            throw $e;
        }
    }
    
    /**
     * 列出所有缓存标签 .
     * 
     * @param array $options 列表选项(limit, order, after, before)
     * @return string 标签列表
     * @throws Exception|GuzzleException
     */
    public function listCacheTags(array $options = []): string
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            // 构建URL参数
            $queryParams = [];
            foreach(['limit', 'order', 'after', 'before'] as $key) {
                if (isset($options[$key])) {
                    $queryParams[$key] = $options[$key];
                }
            }
            
            $url = "{$this->baseUrl}/caching/refs/tags";
            if (!empty($queryParams)) {
                $url .= '?' . http_build_query($queryParams);
            }
            
            $response = $client->get($url, [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
            ]);
            
            return $response->getBody()->getContents();
        } catch (Exception $e) {
            $this->log(['获取缓存标签列表失败', $e->getMessage()], 2);
            throw $e;
        }
    }

    /**
     * 创建缓存标签 . 
     * @param string $tagName 标签名称
     * @param string $cacheId 缓存ID
     * @return array 创建结果
     * @throws Exception|GuzzleException
     */
    public function createCacheTag(string $tagName, string $cacheId)
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }

            if (empty($tagName)) {
                throw new Exception("Tag name is required");
            }

            // 标签名称首字符必须是大小写字母
            if (!preg_match('/^[a-zA-Z]/', $tagName)) {
                throw new Exception("Tag name must start with a letter");
            }

            if (empty($cacheId)) {
                throw new Exception("Cache ID is required");
            }

            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);

            $response = $client->post("{$this->baseUrl}/caching/refs/tags", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],  
                'json' => [
                    'tag' => $tagName,
                    'cache_id' => $cacheId
                ]
            ]);

            return $response->getBody()->getContents();
            
        } catch (Exception $e) {
            $this->log(['创建缓存标签失败', $e->getMessage()], 2);
            throw $e;
        }
    }

    /* 
    获取 Tag 信息
GET https://api.moonshot.cn/v1/caching/refs/tags/{{your_tag_name}}

以下为一次正确请求返回的内容：

{
    "cache_id": "cache-et3tmxxkzr7i11dp6x51",
    "created_at": 1719976735,
    "object": "cache_object.tag",
    "owned_by": "cn0psxxcp7fclnphkcpg",
    "tag": "my-tag"
}
    */
    
    /**
     * 根据缓存名称 获取缓存标签信息
     * 如果缓存名称没有跟缓存ID绑定无法获取 
     * @param string $tagName 标签名称
     * @return mixed|array 标签信息
     * @throws Exception|GuzzleException
     */
    public function retrieveCacheTag(string $tagName)
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            if (empty($tagName)) {
                throw new Exception("Tag name is required");
            }
            
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);

            $apiUrl = "{$this->baseUrl}/caching/refs/tags/{$tagName}"; 
            
            $response = $client->get($apiUrl, [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new Exception("获取缓存标签信息失败: " . $response->getBody()->getContents());
            } else {
                return $response->getBody()->getContents() ;
            }
        } catch (Exception $e) {
            $this->log(['获取缓存标签信息失败', $e->getMessage()], 2);
            throw $e;
        }
    }
    
    /**
     * 获取标签对应的缓存内容.
     * 
     * @param string $tagName 标签名称
     * @return mixed|string 缓存内容
     * @throws Exception|GuzzleException
     */
    public function retrieveCacheTagContent(string $tagName)
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            if (empty($tagName)) {
                throw new Exception("Tag name is required");
            }
            
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            $response = $client->get("{$this->baseUrl}/caching/refs/tags/{$tagName}/content", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new Exception("获取标签缓存内容失败: " . $response->getBody()->getContents());
            } else {
                return $response->getBody()->getContents() ;
            }
            
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // 获取完整的响应内容
            $response = $e->getResponse();
            $fullErrorBody = $response->getBody()->getContents();
            $this->log(['GUZZLEHTTP 获取标签缓存内容失败:', $fullErrorBody], 3);
            throw $e;
        }  catch (Exception $e) {
            $this->log(['获取标签缓存内容失败', $e->getMessage()], 2);
            throw $e;
        }
    }

    /**
     * 根据缓存ID 删除缓存
     * @param string $cacheId 缓存ID
     * @return string 删除结果
     * @throws Exception|GuzzleException
     */
    public function deleteCacheByCacheId(string $cacheId)
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }   

            if (empty($cacheId)) {
                throw new Exception("Cache ID is required");
            }   
            
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);         
            
            $response = $client->delete("{$this->baseUrl}/caching/{$cacheId}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
            ]); 
            
            return $response->getBody()->getContents();
        } catch (Exception $e) {
            $this->log(['删除缓存失败', $e->getMessage()], 2);
            throw $e;
        }
    }   
    
    /**
     * 根据缓存名称 删除缓存标签.
     * 
     * @param string $tagName 标签名称
     * @return mixed|bool 删除是否成功
     * @throws Exception|GuzzleException
     */
    public function deleteCacheTag(string $tagName)
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            if (empty($tagName)) {
                throw new Exception("Tag name is required");
            }
            
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            $response = $client->delete("{$this->baseUrl}/caching/refs/tags/{$tagName}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new Exception("删除缓存标签失败: " . $response->getBody()->getContents());
            } else {
                return $response->getBody()->getContents() ;
            }
        } catch (Exception $e) {
            $this->log(['删除缓存标签失败', $e->getMessage()], 2);
            throw $e;
        }
    }

    /**
     * 获取文本的md5值
     * @param string $text 文本
     * @return string 文本的md5值
     */
    public function getMd5TextId(string $text):string
    {
        return empty($text) ? '' : 'tag-' . md5($this->apiKey . $text);
    }

    /**
     * 
     * 获取文件的md5值
     * @param array $files 文件路径数组
     * @return string 文件的md5值
     */
    public function getMd5FilesId(array $files):string
    {
        $md5Files = [];
        foreach ($files as $file) {
            $md5Files[] = md5_file($file);
        }
        $md5FilesId = '' ;
        foreach ($md5Files as $md5File) {
            $md5FilesId .= $md5File . ',';
        }   
        return empty($md5FilesId) ? '' : 'tag-' . md5($this->apiKey . $md5FilesId);
    }

 
    /**
     * 上传文件并使用上下文缓存生成消息列表
     * 添加支持直接使用cache_tag参数
     * 
     * @param array $files 文件路径数组
     * @param string $text 附加文本
     * @param bool|string $useCache 是否使用上下文缓存，如果是字符串则作为cache_tag使用
     * @param int $cacheTtl 缓存过期时间(秒)，默认300秒(5分钟)
     * @return array 消息列表
     * @throws Exception|GuzzleException
     */
    public function uploadFilesWithContextCaching(array $files, $text = '', $useCache = true, $cacheTtl = 300): array
    {
        $messages = [];
        $fileIds = [];
        $client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10
        ]);

        $apiKey = $this->apiKey;
        if (empty($apiKey)) {
            throw new Exception("API Key is required");
        }

        // 获取 md5_file cache_tag
        $cacheTag = $this->getMd5FilesId($files); 

        // 如果传入的是缓存标签，先检查是否已存在
        if ($useCache) {
            try {

                // 尝试获取已存在的缓存标签
                $cacheTagJson = $this->retrieveCacheTag($cacheTag);
                $this->log(['使用已存在的缓存标签', $cacheTag, $cacheTagJson], 3);
                $cacheTagInfo = json_decode($cacheTagJson, true);
                
                // 如果缓存标签存在且有效，直接返回引用该缓存的消息
                if (isset($cacheTagInfo['cache_id']) && !empty($cacheTagInfo['cache_id'])) {
                    $this->log(['使用已存在的缓存标签', $cacheTag, $cacheTagInfo['cache_id']], 2);
                    
                    return [[
                        'role' => 'cache',
                        'content' => "tag={$cacheTag};reset_ttl={$cacheTtl}",
                    ]];
                }
            } catch (Exception $e) {
                // 如果缓存标签不存在或获取失败，记录日志后继续执行上传流程
                $this->log(['缓存标签不存在或获取失败，将创建新缓存', $e->getMessage()], 2);
            }
        }

        // 如果没有找到有效的缓存标签，则执行正常的上传和缓存流程
        foreach ($files as $file) {
            try {
                // 上传文件
                $fileObject = $this->uploadFile($file);
                if (!isset($fileObject['id'])) {
                    throw new Exception("文件上传失败: 未获取到文件ID");
                }
                
                $fileIds[] = $fileObject['id'];
                
                // 获取文件内容
                $fileContent = $this->retrieveFileContent($fileObject['id']);
                
                // 添加到消息列表
                $messages[] = [
                    'role' => 'system',
                    'content' => $fileContent,
                ];
            } catch (Exception $e) {
                $this->log(['文件处理失败', $file, $e->getMessage()], 3);
                throw $e;
            }
        }

        if (!empty($text)) {
            $messages[] = [
                'role' => 'system',
                'content' => $text,
            ];
        }

        if ($useCache) {
            
            try {
                // 创建缓存请求
                $response = $client->post("{$this->baseUrl}/caching", [
                    'headers' => [
                        'Authorization' => "Bearer {$apiKey}",
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => 'moonshot-v1',  // 使用模型组名
                        'messages' => $messages,
                        'ttl' => $cacheTtl,
                        'tags' => [$cacheTag],
                    ],
                ]);

                if ($response->getStatusCode() !== 200) {
                    throw new Exception("缓存创建失败: " . $response->getBody()->getContents());
                }
                
                $cacheInfo = json_decode($response->getBody()->getContents(), true);
                $cacheId = $cacheInfo['id'] ?? '';

                if (empty($cacheId)) {
                    throw new Exception("缓存ID为空");
                }

                // 创建缓存标签, 缓存标签需要单独创建，否则无法用缓存标签获取缓存信息 
                $this->createCacheTag($cacheTag, $cacheId);
                
                return [[
                    'role' => 'cache',
                    'content' => "tag={$cacheTag};reset_ttl={$cacheTtl}",
                ]];
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                // 获取完整的响应内容
                $response = $e->getResponse();
                $fullErrorBody = $response->getBody()->getContents();
                $this->log(['缓存创建失败 - 完整错误信息:', $fullErrorBody], 3);
                return $messages;
            } catch (Exception $e) {
                $this->log(['缓存创建失败', $e->getMessage(), $e->getTraceAsString()], 3);
                // 缓存创建失败时直接返回消息列表，不影响正常流程
                return $messages;
            }
        }
        
        return $messages;
    }

    /**
     * 上传文件并获取内容
     * @param array       $realFilePaths 文件路径列表
     * @param bool $useCache      是否使用上下文缓存，如果是字符串则作为cache_tag使用
     * @param int         $cacheTtl      缓存过期时间(秒)，默认300秒(5分钟)
     * @return void
     * @throws GuzzleException
     */
    public function uploadAndRetrieveFilesContent(array $realFilePaths = [], bool $useCache = true, int $cacheTtl = 300)
    {
        $messages = $this->uploadFilesWithContextCaching($realFilePaths, '', $useCache, $cacheTtl);
        $this->updateHistoryWithMessages($messages);
    }

    /**
     * 使用缓存处理对话
     */
    public function moonshotWithCache(string $query, array $options = [])
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            if (empty($query)) {
                throw new Exception("查询内容不能为空");
            }

            $cacheTag = $this->getMd5TextId($query); 
            $cacheExists = false ;
            try{
                $cacheTagJson = $this->retrieveCacheTag($cacheTag);
                $cacheTagInfo = json_decode($cacheTagJson, true);
                if (isset($cacheTagInfo['cache_id']) && !empty($cacheTagInfo['cache_id'])) {
                    // $this->log(['使用已存在的缓存标签', $cacheTag, $cacheTagInfo['cache_id']], 2);
                    $cacheExists = true;
                }
            } catch (Exception $e) {
                $this->log(['缓存标签不存在', $cacheTag], 2);
            }
            $cacheTtl = $options['ttl'] ?? 300; 

            // 如果缓存不存在，需要上传文件并创建缓存
            if (!$cacheExists) {
                $this->log(['需要创建新缓存', 'cache_tag' => $cacheTag], 1);

                // 将文件内容添加为系统消息
                $systemMessages[] = [
                    'role' => $options['role'] ?? 'system',
                    'content' => $query
                ];
                
                // 创建缓存
                $cacheOptions = [
                    'model' => "moonshot-v1",  // 使用当前模型配置 固定为 moonshot-v1 
                    'messages' => $systemMessages,
                    'tags' => [$cacheTag]
                ];

                $cacheOptions = array_merge($cacheOptions, $options);
                $cacheResult = $this->createCache($systemMessages, [], $cacheOptions);
                $cacheResult = json_decode($cacheResult, true); 
                
                if (empty($cacheResult) || !isset($cacheResult['id'])) {
                    throw new Exception( __FUNCTION__ ."创建缓存失败");
                }
                $this->createCacheTag($cacheTag, $cacheResult['id']);
                $this->log(['缓存创建成功', 'cache_id' => $cacheResult['id']], 1);
            }

            array_unshift($this->history, [
                'role' => 'cache',
                'content' => "tag={$cacheTag};reset_ttl={$cacheTtl}",
            ]);
        } catch (Exception $e) {
            // 移除缓存消息，避免影响后续对话
            if (!empty($this->history) && $this->history[0]['role'] === 'cache') {
                array_shift($this->history);
            }
            $this->log(['缓存对话失败', $e->getMessage()], 2);
            throw $e;
        }
    }

    /**
     * 对查询内容进行敏感词过滤
     * @param string $query 查询内容
     * @return string 过滤后的内容
     */
    private function sanitizeQuery(string $query)
    {
        $sensitiveWords = [
            '习近平', '天安门广场', '国母', '毛主席', '周总理',
            '朱德', '邓小平', '江泽民', '胡锦涛', '李克强',
            '朱镕基', '温家宝', '中华民族', '共产党', '共产党人', '主席','书记',
            '党委', '党章', '党史', '党性', '党风', '党纪', '党旗', '党徽', '党课', '党校', '党群', '党务', '党派', '党籍', '党章', '党史', '党性', '党风', '党纪', '党旗', '党徽', '党课', '党校', '党群', '党务', '党派', '党籍',
            '纪委'
        ];
        
        return str_replace($sensitiveWords, '', $query);
    }

    /**
     * 安全执行PHP代码并返回结果
     * 
     * @param string $code 要执行的PHP代码
     * @return array 包含输出和返回值的结果数组
     */
    public function executePhpCode(string $code): array
    {
        // 创建临时文件
        $tempFile = tempnam(sys_get_temp_dir(), 'php_code_');
        
        // 提取代码中的最后一个表达式
        $lines = explode("\n", trim($code));
        $lastLine = trim(end($lines));
        
        // 检查最后一行是否是可能的表达式
        $isExpression = preg_match('/^\$?[a-zA-Z0-9_]+\(.*\)$|^\$[a-zA-Z0-9_]+$/', $lastLine);
        
        // 准备完整的PHP代码
        $fullCode = "<?php\n";
        $fullCode .= "ob_start();\n";
        $fullCode .= "try {\n";
        
        // 根据最后一行是否为表达式决定如何处理
        if ($isExpression) {
            // 移除最后一行
            array_pop($lines);
            // 执行前面的代码
            $fullCode .= "    " . implode("\n    ", $lines) . "\n\n";
            // 捕获最后一行的返回值
            $fullCode .= "    \$result = {$lastLine};\n";
        } else {
            // 直接执行全部代码
            $fullCode .= "    " . $code . "\n";
            $fullCode .= "    \$result = null;\n";
        }
        
        $fullCode .= "    \$output = ob_get_clean();\n";
        $fullCode .= "    echo json_encode(['success' => true, 'output' => \$output, 'result' => \$result]);\n";
        $fullCode .= "} catch (Throwable \$e) {\n";
        $fullCode .= "    ob_end_clean();\n";
        $fullCode .= "    echo json_encode(['success' => false, 'error' => \$e->getMessage()]);\n";
        $fullCode .= "}\n";
        
        // 写入临时文件
        file_put_contents($tempFile, $fullCode);
        
        // 执行文件并获取结果
        $result = shell_exec('php ' . escapeshellarg($tempFile));
        
        // 删除临时文件
        unlink($tempFile);
        
        // 解析结果
        $data = json_decode($result, true);
        
        $this->log(['PHP代码执行结果', $data], 3);
        
        return [
            'output' => $data['output'] ?? '',
            'return_value' => $data['result'] ?? ($data['error'] ?? '执行错误')
        ];
    }

    /**
     * 设置部分模式(Partial Mode)，用于控制AI继续从指定内容生成或保持角色一致性
     * 
     * @param string        $content 想要AI继续生成的内容前缀
     * @param string        $name    角色名称，用于角色扮演
     * @param array         $options 额外选项
     * @return $this 返回实例本身以支持链式调用
     */
    public function setPartialMode(string $content = '', ?string $name = null, array $options = []): static
    {
        // 添加一个预填充的assistant消息
        $message = [
            'role' => 'assistant',
            'content' => $content,
            'partial' => true
        ];
        
        // 如果提供了name字段（用于角色扮演）
        if ($name !== null) {
            $message['name'] = $name;
        }
        
        // 将消息添加到历史记录
        $this->history[] = $message;
        $this->partial = true;
        
        // 保存部分模式的状态，用于后续请求
        $this->partialModeData = [
            'content' => $content,
            'name' => $name,
            'options' => $options
        ];
        
        return $this;
    }

    /**
     * 用于处理因长度限制而被截断的响应，继续生成剩余内容
     * 
     * @param string      $prefix  已经生成的内容前缀
     * @param string|null $name    角色名称，用于角色扮演
     * @param array       $options 额外选项，如增加max_tokens
     * @return string 继续生成的内容
     * @throws Exception
     */
    public function continueGeneration(string $prefix, ?string $name = null, array $options = []): string
    {
        try {
            // 重置部分模式状态
            $this->partial = false;
            
            // 设置足够大的max_tokens值避免再次截断
            if (!isset($options['max_tokens']) || $options['max_tokens'] < 4096) {
                $options['max_tokens'] = 8192;
            }
            
            // 使用部分模式设置前缀
            $this->setPartialMode($prefix, $name);
            
            // 获取最后一条用户消息(如果存在)
            $lastUserMessage = '';
            for ($i = count($this->history) - 2; $i >= 0; $i--) {
                if (isset($this->history[$i]['role']) && $this->history[$i]['role'] === 'user') {
                    $lastUserMessage = $this->history[$i]['content'];
                    break;
                }
            }
            
            // 如果找不到用户消息，使用空字符串
            if (empty($lastUserMessage)) {
                $lastUserMessage = '';
            }
            
            // 发送请求继续生成
            // 返回生成的内容
            return $this->moonshot($lastUserMessage, $options);
        } catch (Exception $e) {
            $this->log(['继续生成失败', $e->getMessage()], 1);
            throw $e;
        }
    }

    /**
     * 估计消息的token数量 . 
     * @param string  $messages 消息列表
     * @param string $model    模型名称，默认使用当前设置的模型
     * @return int 返回token数量
     * @throws Exception
     */
    public function estimateTokenCount(string $query, string $model = ''): int
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            // 如果未指定模型，使用当前设置的模型
            if (empty($model)) {
                $model = $this->modelType;
            }

            $this->history[] = ['role' => 'user', 'content' => $query];
            
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            $response = $client->post("{$this->baseUrl}/tokenizers/estimate-token-count", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => "Bearer {$this->apiKey}",
                ],
                'json' => [
                    'model' => $model,
                    'messages' => $this->history,
                ],
            ]);
            
            $responseBody = json_decode($response->getBody(), true);
            
            // 检查是否有错误
            if (isset($responseBody['error'])) {
                throw new Exception("Token计算失败: " .
                    ($responseBody['error']['message'] ?? 'Unknown error'));
            }
            
            // 返回计算结果
            return $responseBody['data']['total_tokens'] ?? 0;
        } catch (Exception $e) {
            $this->log(['Token计算失败', $e->getMessage()], 2);
            throw $e;
        }
    }

    /**
     * 获取API账户余额 .
     * @return array 返回余额信息，包含available_balance, voucher_balance, cash_balance
     * @throws Exception|GuzzleException
     */
    public function getBalance(): array
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            $response = $client->get("{$this->baseUrl}/users/me/balance", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
            ]);
            
            $responseBody = json_decode($response->getBody(), true);
            
            // 检查响应状态
            if (!isset($responseBody['status']) || $responseBody['status'] !== true) {
                throw new Exception("获取余额失败: " .
                    ($responseBody['scode'] ?? 'Unknown error'));
            }
            
            // 返回完整的余额数据
            return [
                'available_balance' => $responseBody['data']['available_balance'] ?? 0,
                'voucher_balance' => $responseBody['data']['voucher_balance'] ?? 0,
                'cash_balance' => $responseBody['data']['cash_balance'] ?? 0,
            ];
        } catch (Exception $e) {
            $this->log(['余额查询失败', $e->getMessage()], 2);
            throw $e;
        }
    }

    /**
     * 获取API可用余额（简化方法）
     * @return float|int 返回可用余额
     * @throws GuzzleException
     */
    public function getAvailableBalance(): float|int
    {
        $balance = $this->getBalance();
        return $balance['available_balance'];
    }

    /**
     * 检查账户余额是否充足
     * @param float|int $minimumBalance 最小所需余额
     * @return bool 余额是否充足
     * @throws Exception|GuzzleException
     */
    public function hasEnoughBalance(float|int $minimumBalance = 0): bool
    {
        $balance = $this->getBalance();
        return $balance['available_balance'] > $minimumBalance;
    }

    /**
     * 提供完全兼容OpenAI接口的聊天完成功能
     * 方便从OpenAI SDK迁移的用户
     * 
     * @param array $params OpenAI格式的参数
     * @return array 符合OpenAI响应格式的结果
     * @throws Exception
     */
    public function createChatCompletion(array $params): array
    {
        // 保存原始历史
        $originalHistory = $this->history;
        
        try {
            // 完全使用传入的messages
            $this->history = !empty($params['messages'])
                ? array_filter($params['messages'], function($msg) {
                    return isset($msg['role']) && isset($msg['content']);
                }) 
                : $this->history;
            
            // 处理temperature=0且n>1的情况
            if (isset($params['temperature']) && $params['temperature'] == 0 && 
                isset($params['n']) && $params['n'] > 1) {
                throw new Exception("当temperature=0时，n值必须为1");
            }
            
            // 准备新的请求选项，移除messages（因为已经设置到history中）
            $options = $params;
            unset($options['messages']);
            
            // 如果没有指定模型，使用当前实例的模型
            if (!isset($options['model'])) {
                $options['model'] = $this->modelType;
            }
            
            // 调用moonshot方法，但设置不添加到历史中
            $response = $this->sendRequest($this->baseUrl . '/chat/completions', [
                RequestOptions::JSON => array_merge([
                    'model' => $options['model'],
                    'messages' => $this->history,
                    'temperature' => 0.3,
                    'stream' => false,
                ], $options),
                RequestOptions::HEADERS => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'tag' => $this->dialogueTag,
                ]
            ]);
            
            // 返回OpenAI兼容的响应
            return json_decode($response->getBody()->getContents(), true);
        } finally {
            // 恢复原始历史
            $this->history = $originalHistory;
        }
    }

    /**
     * 增强错误处理
     *
     * @param ClientException $e 客户端异常
     * @return ClientException|Exception 处理后的异常
     */
    protected function enhanceErrorHandling(ClientException $e): ClientException|Exception
    {
        return ErrorHandler::handleApiError($e);
    }

    /**
     * 设置API基础URL
     * 便于切换不同环境或兼容其他实现相同API的服务
     * 
     * @param string $baseUrl API基础URL
     * @return $this
     */
    public function setBaseUrl(string $baseUrl): static
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        return $this;
    }

    /**
     * 优化工具选择参数处理
     * 
     * @param mixed $toolChoice 工具选择参数
     * @return string|array|null 处理后的工具选择参数
     */
    protected function processToolChoice(mixed $toolChoice): string|array|null
    {
        // 如果是字符串"auto"或"none"或null，直接返回
        if ($toolChoice === 'auto' || $toolChoice === 'none' || $toolChoice === null) {
            return $toolChoice;
        }
        
        // 如果是required，记录警告并转换为auto
        if ($toolChoice === 'required') {
            $this->log('Moonshot API目前不支持tool_choice=required，将使用auto代替', 2);
            return 'auto';
        }
        
        // 如果是对象格式，检查是否合法的function选择
        if (is_array($toolChoice) && isset($toolChoice['type']) && $toolChoice['type'] === 'function') {
            // Moonshot API支持指定function调用
            return $toolChoice;
        }
        
        // 其他情况，使用默认auto
        $this->log(['警告: 不支持的tool_choice值', $toolChoice], 2);
        return 'auto';
    }

    /**
     * 设置系统消息
     * 
     * @param string $content         系统消息内容
     * @param bool   $replaceExisting 是否替换现有系统消息，默认为true
     * @return $this
     */
    public function setSystemMessage(string $content, bool $replaceExisting = true): static
    {
        // 清除现有的系统消息
        if ($replaceExisting) {
            $this->history = array_filter($this->history, function($msg) {
                return !isset($msg['role']) || $msg['role'] !== 'system';
            });
        }
        
        // 添加新的系统消息到历史的开头
        array_unshift($this->history, [
            'role' => 'system',
            'content' => $content
        ]);
        
        return $this;
    }

    /**
     * 获取当前系统消息
     * 
     * @return array 所有系统消息列表
     */
    public function getSystemMessages(): array
    {
        return array_filter($this->history, function($msg) {
            return isset($msg['role']) && $msg['role'] === 'system';
        });
    }

    /**
     * 导出会话状态，包含历史记录和其他相关信息
     * 
     * @return array 会话状态信息
     */
    public function exportSession(): array
    {
        return [
            'dialogue_tag' => $this->dialogueTag,
            'model_type' => $this->modelType,
            'history' => $this->history,
            'partial' => $this->partial,
            'message_content' => $this->messageContent,
            'timestamp' => time()
        ];
    }

    /**
     * 从导出的会话状态中恢复会话
     * 
     * @param array $sessionData 会话状态信息
     * @return $this
     */
    public function importSession(array $sessionData): static
    {
        if (isset($sessionData['history'])) {
            $this->history = $sessionData['history'];
        }
        
        if (isset($sessionData['dialogue_tag'])) {
            $this->dialogueTag = $sessionData['dialogue_tag'];
        }
        
        if (isset($sessionData['model_type'])) {
            $this->modelType = $sessionData['model_type'];
        }
        
        if (isset($sessionData['partial'])) {
            $this->partial = $sessionData['partial'];
        }
        
        if (isset($sessionData['message_content'])) {
            $this->messageContent = $sessionData['message_content'];
        }
        
        return $this;
    }

    /**
     * 测试API连接的可靠性 .
     * 发送一个简单的请求来检测API连接状态，适用于网络环境测试
     * 
     * @param int $maxRetries 最大重试次数，默认为3
     * @param int $timeout    超时时间（秒），默认为10
     * @return bool 连接是否成功
     */
    public function testConnection(int $maxRetries = 3, int $timeout = 10): bool
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            $client = new Client([
                'timeout' => $timeout,
                'connect_timeout' => $timeout
            ]);
            
            // 准备请求选项
            $requestOptions = [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
                'timeout' => $timeout,
                'connect_timeout' => $timeout
            ];
            
            // 使用更可靠的chat/completions API端点，通过OPTIONS请求检查API是否可用
            // OPTIONS请求通常用于检查资源是否可用，不会产生实际的API调用
            try {
                // 尝试请求chat/completions端点，这是最常用的API端点
                $response = $client->request('OPTIONS', 'https://api.moonshot.cn/v1/chat/completions', $requestOptions);
                $statusCode = $response->getStatusCode();
                $this->log("API连接测试成功，HTTP状态码: {$statusCode}", 1);
                return true;
            } catch (ClientException $e) {
                // 如果OPTIONS请求失败，尝试发送最小化的实际请求
                if ($e->getResponse()->getStatusCode() == 404 || $e->getResponse()->getStatusCode() == 405) {
                    try {
                        // 发送一个最小化的请求，只获取响应头
                        $miniRequest = [
                            'headers' => $requestOptions['headers'],
                            'timeout' => $timeout,
                            'connect_timeout' => $timeout,
                            'json' => [
                                'model' => 'moonshot-v1-8k',
                                'messages' => [
                                    ['role' => 'user', 'content' => 'test']
                                ],
                                'stream' => false,
                                'max_tokens' => 1
                            ]
                        ];
                        
                        $response = $client->request('POST', 'https://api.moonshot.cn/v1/chat/completions', $miniRequest);
                        $statusCode = $response->getStatusCode();
                        $this->log("API连接测试成功，HTTP状态码: {$statusCode}", 1);
                        return true;
                    } catch (ClientException $ce) {
                        // 检查是否是401/403，这表示API密钥认证有问题但API本身是可用的
                        $errorCode = $ce->getResponse()->getStatusCode();
                        if ($errorCode == 401 || $errorCode == 403) {
                            $this->log("API连接可用，但API密钥认证失败，HTTP状态码: {$errorCode}", 2);
                            
                            // 特别处理账户未激活的情况
                            $responseBody = $ce->getResponse()->getBody()->getContents();
                            if (strpos($responseBody, 'not active') !== false || 
                                strpos($responseBody, '未激活') !== false || 
                                strpos($responseBody, '未开通') !== false) {
                                throw Exceptions\ErrorHandler::createError(
                                    "账户未激活或已被禁用，请联系管理员",
                                    403,
                                    'account_inactive_error',
                                    json_decode($responseBody, true),
                                    $ce
                                );
                            }
                            
                            return false;
                        }
                        
                        throw $ce;
                    }
                }
                
                throw $e;
            }
        } catch (ClientException $e) {
            // 处理4xx错误
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();
            
            $errorMessage = "API连接测试失败，HTTP状态码: {$statusCode}";
            try {
                $responseData = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
                if (isset($responseData['error']['message'])) {
                    $errorMessage .= ", 错误信息: " . $responseData['error']['message'];
                } elseif (isset($responseData['message'])) {
                    $errorMessage .= ", 错误信息: " . $responseData['message'];
                }
            } catch (\JsonException $je) {
                $errorMessage .= ", 响应内容: " . substr($responseBody, 0, 100);
            }
            
            $this->log([$errorMessage], 2);
            
            // 特别处理账户未激活的情况
            if ($statusCode === 403 && 
                (strpos($responseBody, 'not active') !== false || 
                 strpos($responseBody, '未激活') !== false || 
                 strpos($responseBody, '未开通') !== false)) {
                throw Exceptions\ErrorHandler::createError(
                    "账户未激活或已被禁用，请联系管理员",
                    403,
                    'account_inactive_error',
                    json_decode($responseBody, true),
                    $e
                );
            }
            
            return false;
        } catch (Exception $e) {
            $this->log(['API连接测试失败', $e->getMessage()], 2);
            return false;
        }
    }

    /**
     * 使用流式输出方式与Moonshot AI交互 .
     * 
     * @param string $query 用户查询
     * @param callable $chunkCallback 数据块回调函数 function($chunk, $index, $isDone)
     * @param callable|null $completeCallback 完成回调函数 function($allContent, $usage, $finishReason)
     * @param array $options 额外选项
     * @return array 包含完整响应内容的数组
     * @throws Exception
     */
    public function streamChat(string $query, callable $chunkCallback, ?callable $completeCallback = null, array $options = []): array
    {
        try {
            // 验证API密钥
            $this->validateApiKey();
            
            // 处理查询内容
            $query = $this->sanitizeQuery($query);
            
            // 保存选项参数
            $this->lastOptions = $options;
            
            // 设置流式输出参数
            $options['stream'] = true;
            
            // 初始化流式输出状态
            $this->initializeStreamState();
            
            // 准备请求参数
            $requestOptions = $this->prepareRequestOptions($query, $options);
            
            // 请求超时设置（默认5分钟）
            $timeout = $options['timeout'] ?? 300;
            $connectTimeout = $options['connect_timeout'] ?? 30;
            
            // 创建Guzzle客户端
            $client = new Client([
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout
            ]);
            
            // 处理流式响应
            $result = $this->handleStreamResponse(
                $client, 
                $this->baseUrl . '/chat/completions', 
                $requestOptions, 
                $chunkCallback, 
                $completeCallback
            );
            
            return $result;
        } catch (GuzzleException $e) {
            $this->log(['流式输出失败', 'error' => $e->getMessage()], 2);
            // 使用ErrorHandler处理异常并提供详细错误位置信息
            throw Exceptions\ErrorHandler::handleApiError($e);
        } catch (Exception $e) {
            $this->log(['流式输出失败', 'error' => $e->getMessage()], 2);
            throw Exceptions\ErrorHandler::createError(
                "流式输出失败: " . $e->getMessage(), 
                0, 
                'stream_chat_error', 
                null, 
                $e
            );
        }
    }

    /**
     * 初始化流式输出状态
     */
    private function initializeStreamState(): void
    {
        $this->streamActive = true;
        $this->streamBuffer = '';
        $this->streamProgress = 0;
    }

    /**
     * 处理流式响应 . 
     *
     * @param Client        $client           Guzzle客户端
     * @param string        $url              请求URL
     * @param array         $requestOptions   请求选项
     * @param callable      $chunkCallback    数据块回调
     * @param callable|null $completeCallback 完成回调
     * @return array 处理结果
     */
    private function handleStreamResponse(
        Client $client, 
        string $url, 
        array $requestOptions, 
        callable $chunkCallback, 
        ?callable $completeCallback = null
    ): array {
        // 发送请求但保持连接打开以接收流式响应
        $response = $client->post($url, [
            RequestOptions::JSON => $requestOptions[RequestOptions::JSON],
            RequestOptions::HEADERS => $requestOptions[RequestOptions::HEADERS],
            RequestOptions::STREAM => true
        ]);
        
        // 初始化状态变量
        $allContent = '';
        $usage = null;
        $finishReason = null;
        $index = 0; // 数据块索引
        $dataBuffer = '';
        $isDone = false;
        
        // 读取流式响应
        $body = $response->getBody();
        $readTimeout = microtime(true) + ($requestOptions['timeout'] ?? 300);
        
        while (!$body->eof() && !$isDone && $this->streamActive && microtime(true) < $readTimeout) {
            // 添加错误恢复机制
            try {
                $line = $body->read(4096);
                if ($line === '') {
                    // 如果没有数据，短暂休息避免CPU过载
                    usleep(10000); // 10ms
                    continue;
                }
                
                // 按行分割数据
                $lines = explode("\n", $line);
                
                foreach ($lines as $currentLine) {
                    // 如果流已停止，退出处理
                    if (!$this->streamActive) {
                        $isDone = true;
                        break;
                    }
                    
                    // 处理当前行
                    $result = $this->processStreamLine(
                        $currentLine,
                        $dataBuffer,
                        $allContent,
                        $usage,
                        $finishReason,
                        $index,
                        $chunkCallback,
                        $isDone
                    );
                    
                    // 更新状态
                    $dataBuffer = $result['dataBuffer'];
                    $index = $result['index'];
                    $isDone = $result['isDone'];
                    
                    if ($isDone) {
                        break;
                    }
                }
            } catch (Exception $e) {
                $this->log(['流处理中遇到错误，尝试恢复', 'error' => $e->getMessage()], 2);
                // 短暂暂停，然后尝试继续
                usleep(100000); // 100ms
            }
        }
        
        // 处理最后一个可能的数据块
        if (!empty($dataBuffer) && $dataBuffer !== '[DONE]' && $this->streamActive) {
            $chunk = $this->handleStreamChunk($dataBuffer, $allContent, $usage, $finishReason);
            call_user_func($chunkCallback, $chunk, $index, false);
            // 更新流式进度
            $this->streamProgress++;
            $this->streamBuffer = $allContent;
        }
        
        // 关闭流式输出状态
        $this->streamActive = false;
        
        // 如果存在完成回调，调用它
        if (is_callable($completeCallback) && $this->streamProgress > 0) {
            call_user_func($completeCallback, $allContent, $usage, $finishReason);
        }
        
        // 更新历史记录
        if (!empty($allContent)) {
            $this->updateHistory($allContent);
        }
        
        // 返回处理结果
        return [
            'content' => $allContent,
            'usage' => $usage,
            'finish_reason' => $finishReason
        ];
    }

    /**
     * 处理流式输出的单行数据
     * 
     * @param string $currentLine 当前行数据
     * @param string $dataBuffer 数据缓冲区
     * @param string $allContent 累积的所有内容
     * @param array|null $usage 使用情况
     * @param string|null $finishReason 结束原因
     * @param int $index 数据块索引
     * @param callable $chunkCallback 块回调
     * @param bool $isDone 是否已完成
     * @return array 处理结果
     */
    private function processStreamLine(
        string $currentLine,
        string $dataBuffer,
        string &$allContent,
        ?array &$usage,
        ?string &$finishReason,
        int $index,
        callable $chunkCallback,
        bool $isDone
    ): array {
        // 跳过空行
        if (trim($currentLine) === '') {
            if (!empty($dataBuffer)) {
                $chunk = $this->handleStreamChunk($dataBuffer, $allContent, $usage, $finishReason);
                // 调用回调函数
                $callbackResult = call_user_func($chunkCallback, $chunk, $index++, false);
                // 如果回调返回false，停止处理
                if ($callbackResult === false) {
                    $isDone = true;
                }
                
                $dataBuffer = '';
                // 更新流式进度
                $this->streamProgress++;
                $this->streamBuffer = $allContent;
            }
            return ['dataBuffer' => $dataBuffer, 'index' => $index, 'isDone' => $isDone];
        }
        
        // 处理数据行
        if (strpos($currentLine, 'data:') === 0) {
            // 如果已经有数据，先处理之前的数据
            if (!empty($dataBuffer)) {
                $chunk = $this->handleStreamChunk($dataBuffer, $allContent, $usage, $finishReason);
                // 调用回调函数
                $callbackResult = call_user_func($chunkCallback, $chunk, $index++, false);
                // 如果回调返回false，停止处理
                if ($callbackResult === false) {
                    $isDone = true;
                    return ['dataBuffer' => '', 'index' => $index, 'isDone' => true];
                }
                
                $dataBuffer = '';
                // 更新流式进度
                $this->streamProgress++;
                $this->streamBuffer = $allContent;
            }
            
            // 获取新数据
            $data = substr($currentLine, 5); // 去除"data:"前缀
            $data = trim($data);
            
            // 检查是否是结束标记
            if ($data === '[DONE]') {
                call_user_func($chunkCallback, null, $index, true);
                return ['dataBuffer' => '', 'index' => $index, 'isDone' => true];
            }
            
            $dataBuffer = $data;
        } else {
            // 追加数据到当前缓冲区
            $dataBuffer .= "\n" . $currentLine;
        }
        
        return ['dataBuffer' => $dataBuffer, 'index' => $index, 'isDone' => $isDone];
    }

    /**
     * 处理流式数据块
     * 
     * @param string $data JSON格式的数据
     * @param string &$allContent 累计的内容
     * @param array &$usage 使用情况
     * @param string &$finishReason 结束原因
     * @return array|null 处理后的数据块
     */
    private function handleStreamChunk($data, &$allContent, &$usage, &$finishReason)
    {
        try {
            $chunk = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log(['JSON解析错误', $data], 2);
                return null;
            }
            
            // 处理数据块
            $content = '';
            $choices = $chunk['choices'] ?? [];
            
            foreach ($choices as $choice) {
                // 从delta中提取内容
                $delta = $choice['delta'] ?? [];
                if (isset($delta['content'])) {
                    $content .= $delta['content'];
                    $allContent .= $delta['content'];
                }
                
                // 获取完成原因
                if (isset($choice['finish_reason'])) {
                    $finishReason = $choice['finish_reason'];
                }
                
                // 获取usage信息(通常在最后一个数据块)
                if (isset($choice['usage'])) {
                    $usage = $choice['usage'];
                }
            }
            
            // 有些API实现会在主对象中包含usage
            if (isset($chunk['usage'])) {
                $usage = $chunk['usage'];
            }
            
            return [
                'id' => $chunk['id'] ?? null,
                'object' => $chunk['object'] ?? null,
                'created' => $chunk['created'] ?? null,
                'model' => $chunk['model'] ?? null,
                'content' => $content,
                'choices' => $choices,
                'raw' => $chunk
            ];
        } catch (Exception $e) {
            $this->log(['处理流式数据块出错', $e->getMessage()], 2);
            return null;
        }
    }

    /**
     * 使用流式输出方式与Moonshot AI交互，同时支持多个回答选项(n>1)
     * 
     * @param string        $query            用户查询
     * @param callable      $chunkCallback    数据块回调函数 function($chunk, $index, $isDone, $choiceIndex)
     * @param callable|null $completeCallback 完成回调函数 function($allContents, $usage, $finishReasons)
     * @param array         $options          额外选项, 支持n参数指定返回多个回答
     * @return array 包含完整响应内容的数组
     * @throws Exception
     */
    public function streamChatWithChoices(string $query, callable $chunkCallback, ?callable $completeCallback = null, array $options = []): array
    {
        try {
            // 检查API密钥
            if (empty($this->apiKey)) {
                throw new Exception("API Key is required");
            }
            
            // 处理查询内容
            $query = $this->sanitizeQuery($query);
            
            // 保存选项参数，用于可能的递归调用
            $this->lastOptions = $options;
            
            // 设置流式输出参数
            $options['stream'] = true;
            
            // 初始化流式输出状态
            $this->streamActive = true;
            $this->streamBuffer = '';
            $this->streamProgress = 0;
            
            // 如果没有明确设置n，默认为1
            if (!isset($options['n'])) {
                $options['n'] = 1;
            }
            
            // 根据temperature=0的限制检查n参数
            if (isset($options['temperature']) && $options['temperature'] == 0 && $options['n'] > 1) {
                $this->log('警告: 当temperature=0时，n参数必须为1，已自动调整n=1', 2);
                $options['n'] = 1;
            }
            
            // 准备请求参数
            $requestOptions = $this->prepareRequestOptions($query, $options);
            
            // 创建Guzzle客户端
            $client = new Client([
                'timeout' => $requestOptions['timeout'] ?? 5 * 60,
                'connect_timeout' => $requestOptions['connect_timeout'] ?? 4 * 60
            ]);
            
            // 发送请求但保持连接打开以接收流式响应
            $response = $client->post($this->baseUrl . '/chat/completions', [
                RequestOptions::JSON => $requestOptions[RequestOptions::JSON],
                RequestOptions::HEADERS => $requestOptions[RequestOptions::HEADERS],
                RequestOptions::STREAM => true
            ]);
            
            // 初始化变量，用于跟踪每个选项的内容
            $n = $options['n'];
            $allContents = array_fill(0, $n, '');
            $usage = [];
            $finishReasons = array_fill(0, $n, null);
            $index = 0; // 数据块索引
            $dataBuffer = '';
            $isDone = false;
            
            // 读取流式响应
            $body = $response->getBody();
            while (!$body->eof() && !$isDone && $this->streamActive) {
                $line = $body->read(4096);
                // 按行分割数据
                $lines = explode("\n", $line);
                foreach ($lines as $currentLine) {
                    // 跳过空行
                    if (trim($currentLine) === '') {
                        if (!empty($dataBuffer)) {
                            $results = $this->handleMultiChoiceStreamChunk($dataBuffer, $allContents, $usage, $finishReasons);
                            
                            $continueProcessing = true;
                            // 对每个选项的内容分别回调
                            foreach ($results as $choiceIndex => $chunk) {
                                // 调用回调函数，如果任一回调返回false，则停止处理
                                if (call_user_func($chunkCallback, $chunk, $index, false, $choiceIndex) === false) {
                                    $continueProcessing = false;
                                    break;
                                }
                            }
                            
                            if (!$continueProcessing) {
                                $isDone = true;
                                break;
                            }
                            
                            $index++;
                            $dataBuffer = '';
                            // 更新流式进度
                            $this->streamProgress++;
                            $this->streamBuffer = json_encode($allContents);
                        }
                        continue;
                    }
                    
                    // 处理数据行
                    if (strpos($currentLine, 'data:') === 0) {
                        // 如果已经有数据，先处理之前的数据
                        if (!empty($dataBuffer)) {
                            $results = $this->handleMultiChoiceStreamChunk($dataBuffer, $allContents, $usage, $finishReasons);
                            
                            $continueProcessing = true;
                            // 对每个选项的内容分别回调
                            foreach ($results as $choiceIndex => $chunk) {
                                // 调用回调函数，如果任一回调返回false，则停止处理
                                if (call_user_func($chunkCallback, $chunk, $index, false, $choiceIndex) === false) {
                                    $continueProcessing = false;
                                    break;
                                }
                            }
                            
                            if (!$continueProcessing) {
                                $isDone = true;
                                break;
                            }
                            
                            $index++;
                            $dataBuffer = '';
                            // 更新流式进度
                            $this->streamProgress++;
                            $this->streamBuffer = json_encode($allContents);
                        }
                        
                        // 获取新数据
                        $data = substr($currentLine, 5); // 去除"data:"前缀
                        $data = trim($data);
                        
                        // 检查是否是结束标记
                        if ($data === '[DONE]') {
                            // 对每个选项发送完成信号
                            for ($i = 0; $i < $n; $i++) {
                                call_user_func($chunkCallback, null, $index, true, $i);
                            }
                            break 2; // 跳出两层循环
                        }
                        
                        $dataBuffer = $data;
                    } else {
                        // 追加数据到当前缓冲区
                        $dataBuffer .= "\n" . $currentLine;
                    }
                }
            }
            
            // 处理最后一个可能的数据块
            if (!empty($dataBuffer) && $dataBuffer !== '[DONE]' && $this->streamActive) {
                $results = $this->handleMultiChoiceStreamChunk($dataBuffer, $allContents, $usage, $finishReasons);
                
                // 对每个选项的内容分别回调
                foreach ($results as $choiceIndex => $chunk) {
                    call_user_func($chunkCallback, $chunk, $index, false, $choiceIndex);
                }
                
                // 更新流式进度
                $this->streamProgress++;
                $this->streamBuffer = json_encode($allContents);
            }
            
            // 关闭流式输出状态
            $this->streamActive = false;
            
            // 如果存在完成回调，调用它
            if (is_callable($completeCallback) && $this->streamProgress > 0) {
                call_user_func($completeCallback, $allContents, $usage, $finishReasons);
            }
            
            // 更新历史记录 (选择第一个回答添加到历史)
            if (!empty($allContents[0])) {
                $this->updateHistory($allContents[0]);
            }
            
            // 计算token数量(如果usage为空)
            if (is_null($usage)) {
                try {
                    $totalTokens = 0;
                    foreach ($allContents as $content) {
                        if (!empty($content)) {
                            $tokenCount = $this->calculateStreamTokens($content, $options['model'] ?? '');
                            $totalTokens += $tokenCount;
                        }
                    }
                    
                    if ($totalTokens > 0) {
                        $usage = [
                            'completion_tokens' => $totalTokens,
                            'prompt_tokens' => 0, // 无法确定，设为0
                            'total_tokens' => $totalTokens // 实际应该加上prompt_tokens
                        ];
                    }
                } catch (Exception $e) {
                    $this->log(['计算多选项Token失败', $e->getMessage()], 2);
                }
            }
            
            return [
                'contents' => $allContents,
                'usage' => $usage,
                'finish_reasons' => $finishReasons,
                'tokens_estimated' => is_null($usage) // 标记usage是否为估算值
            ];
        } catch (Exception $e) {
            // 关闭流式输出状态
            $this->streamActive = false;
            
            $this->log($e->getMessage(), 1);
            $this->log($e->getTraceAsString(), 1);
            throw $e;
        }
    }

    /**
     * 处理多选项流式数据块
     * 
     * @param string $data          JSON格式的数据
     * @param array &$allContents   各选项的累计内容
     * @param array &$usage         使用情况
     * @param array &$finishReasons 各选项的结束原因
     * @return array 各选项的处理后的数据块
     */
    private function handleMultiChoiceStreamChunk(string $data, array &$allContents, array &$usage, array &$finishReasons): array
    {
        try {
            $chunk = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log(['JSON解析错误', $data], 2);
                return [];
            }
            
            $results = [];
            $choices = $chunk['choices'] ?? [];
            
            foreach ($choices as $choice) {
                $choiceIndex = $choice['index'] ?? 0;
                
                // 确保索引在有效范围内
                if ($choiceIndex >= count($allContents)) {
                    continue;
                }
                
                // 从delta中提取内容
                $delta = $choice['delta'] ?? [];
                $content = '';
                
                if (isset($delta['content'])) {
                    $content = $delta['content'];
                    $allContents[$choiceIndex] .= $content;
                }
                
                // 获取完成原因
                if (isset($choice['finish_reason'])) {
                    $finishReasons[$choiceIndex] = $choice['finish_reason'];
                }
                
                // 获取usage信息(通常在最后一个数据块)
                if (isset($choice['usage'])) {
                    $usage = $choice['usage'];
                }
                
                // 准备当前选项的结果
                $results[$choiceIndex] = [
                    'id' => $chunk['id'] ?? null,
                    'object' => $chunk['object'] ?? null,
                    'created' => $chunk['created'] ?? null,
                    'model' => $chunk['model'] ?? null,
                    'content' => $content,
                    'choice_index' => $choiceIndex,
                    'raw' => $choice
                ];
            }
            
            // 有些API实现会在主对象中包含usage
            if (isset($chunk['usage'])) {
                $usage = $chunk['usage'];
            }
            
            return $results;
        } catch (Exception $e) {
            $this->log(['处理多选项流式数据块出错', $e->getMessage()], 2);
            return [];
        }
    }

    /**
     * 停止流式输出处理
     * 此方法可用于在流式输出处理过程中主动停止处理
     * 
     * @param bool $cleanupStream 是否清理流式输出变量(默认true)
     * @return bool 成功停止返回true
     */
    public function stopStream(bool $cleanupStream = true): bool
    {
        try {
            $this->streamActive = false;
            
            if ($cleanupStream) {
                // 清理流式输出相关变量
                $this->streamBuffer = '';
                $this->streamProgress = 0;
            }
            
            return true;
        } catch (Exception $e) {
            $this->log(['停止流式输出失败', $e->getMessage()], 1);
            return false;
        }
    }

    /**
     * 计算流式输出中已接收内容的token数量
     * 
     * @param string $content 已接收的内容
     * @param string $model   使用的模型名称
     * @return int|null 计算的token数量，失败返回null
     */
    public function calculateStreamTokens(string $content, string $model = ''): ?int
    {
        try {
            return $this->estimateTokenCount($content, $model);
        } catch (Exception $e) {
            $this->log(['计算流式token失败', $e->getMessage()], 2);
            // 如果无法使用API计算，使用简单的估算方法
            return $this->estimateTokensSimple($content);
        }
    }

    /**
     * 简单估算文本的token数量
     * 
     * @param string $text 要估算的文本
     * @return int 估算的token数量
     */
    private function estimateTokensSimple(string $text): int
    {
        if (empty($text)) {
            return 0;
        }
        
        // 中文字符约为1个token，英文单词约为0.75个token
        // 这只是一个粗略估计，不同模型可能有差异
        $chineseChars = preg_match_all('/[\p{Han}]/u', $text);
        $totalChars = mb_strlen($text, 'UTF-8');
        $nonChineseChars = $totalChars - $chineseChars;
        
        // 估算英文单词数量
        $words = preg_match_all('/\b[a-zA-Z0-9]+\b/', $text);
        
        // 计算估算值
        return $chineseChars + ceil($words * 0.75) + ceil(($nonChineseChars - $words) * 0.25);
    }

    /**
     * 使用工具调用功能与Moonshot AI交互
     * 
     * @param string        $query        用户查询
     * @param array         $tools        工具定义数组
     * @param callable|null $toolExecutor 工具执行器函数，接收 (name, arguments) 参数
     * @param array         $options      额外选项
     * @return array 包含完整响应内容的数组
     * @throws Exception
     */
    public function executeToolCallsWithExecutor(string $query, array $tools, ?callable $toolExecutor = null, array $options = []): array
    {
        // 初始化消息列表
        $messages = [];
        
        // 获取系统消息
        $systemMessages = $this->getSystemMessages();
        
        // 如果有系统消息，添加到消息列表
        if (!empty($systemMessages)) {
            foreach ($systemMessages as $systemMessage) {
                $messages[] = $systemMessage;
            }
        }
        
        // 如果是系统消息数组，添加到消息列表
        foreach ($this->history as $message) {
            if (isset($message['role']) && $message['role'] === 'system') {
                $messages[] = $message;
            }
        }
        
        // 添加历史消息
        foreach ($this->history as $message) {
            if (isset($message['role']) && $message['role'] !== 'system') {
                $messages[] = $message;
            }
        }
        
        // 添加当前查询
        if (!empty($query)) {
            $messages[] = ['role' => 'user', 'content' => $this->sanitizeQuery($query)];
        }
        
        $finishReason = null;
        $toolMap = [];
        
        // 循环执行工具调用直到完成
        while ($finishReason === null || $finishReason === 'tool_calls') {
            try {
                // 准备请求参数
                $requestOptions = [
                    'model' => $this->modelType,
                    'messages' => $messages,
                    'tools' => $tools,
                    'temperature' => $options['temperature'] ?? 0.7,
                ];
                
                // 添加其他选项
                foreach ($options as $key => $value) {
                    if (!isset($requestOptions[$key])) {
                        $requestOptions[$key] = $value;
                    }
                }
                
                // 发送请求
                $completion = $this->sendRequest($this->baseUrl . '/chat/completions', [
                    \GuzzleHttp\RequestOptions::JSON => $requestOptions,
                    \GuzzleHttp\RequestOptions::HEADERS => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                ]);
                
                // 解析响应
                $responseData = is_string($completion) ? json_decode($completion, true) : $completion;
                
                if (!isset($responseData['choices'][0]) || !isset($responseData['choices'][0]['message'])) {
                    throw new Exception("Invalid response format from API");
                }
                
                $choice = $responseData['choices'][0];
                $finishReason = $choice['finish_reason'] ?? null;
                $message = $choice['message'];
                
                // 将 assistant 消息添加到上下文
                $messages[] = $message;
                
                // 如果需要工具调用
                if ($finishReason === 'tool_calls' && isset($message['tool_calls']) && is_callable($toolExecutor)) {
                    foreach ($message['tool_calls'] as $toolCall) {
                        $toolCallId = $toolCall['id'] ?? null;
                        $function = $toolCall['function'] ?? null;
                        
                        if (!$toolCallId || !$function) {
                            continue;
                        }
                        
                        $name = $function['name'] ?? 'unknown';
                        $arguments = json_decode($function['arguments'] ?? '{}', true);
                        
                        // 使用工具执行器执行工具
                        $result = call_user_func($toolExecutor, $name, $arguments);
                        
                        // 添加工具执行结果
                        $messages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCallId,
                            'name' => $name,
                            'content' => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE),
                        ];
                    }
                } else {
                    // 更新聊天历史
                    if (isset($message['content']) && !empty($message['content'])) {
                        $this->updateHistory($message['content']);
                    }
                    
                    return [
                        'content' => $message['content'] ?? '',
                        'tool_calls' => $message['tool_calls'] ?? null,
                        'finish_reason' => $finishReason,
                        'usage' => $responseData['usage'] ?? null,
                    ];
                }
            } catch (Exception $e) {
                $this->log(['工具调用执行出错', $e->getMessage()], 1);
                throw $e;
            }
        }
        
        // 最终响应（正常情况下不会执行到这里，会在循环中返回）
        return [
            'content' => $message['content'] ?? '',
            'tool_calls' => $message['tool_calls'] ?? null,
            'finish_reason' => $finishReason,
            'usage' => $responseData['usage'] ?? null,
        ];
    }

    /**
     * 使用内置的Web搜索功能与MoonshotAI交互
     * 
     * 该方法使用MoonshotAI内置的$web_search工具函数实现联网搜索功能
     * 流程与普通工具调用相同，但不需要开发者实现搜索、解析等逻辑
     * 
     * @param string $query   用户查询内容
     * @param array  $options 额外选项
     * @return array 搜索结果和AI回答
     * @throws Exception
     */
    public function webSearch(string $query, array $options = []): array
    {
        try {
            // 检查API密钥
            $this->validateApiKey();
            
            // 处理查询内容
            $query = $this->sanitizeQuery($query);
            
            // 保存选项参数
            $this->lastOptions = $options;
            
            // 添加Web搜索工具 - 使用builtin_function类型的$web_search
            $webSearchTool = [
                "type" => "builtin_function",  // 使用builtin_function表示MoonshotAI内置工具
                "function" => [
                    "name" => "\$web_search"   // $符号前缀表示内置函数
                ]
            ];
            
            // 确保tools参数是数组，并添加$web_search工具
            if (isset($options['tools']) && is_array($options['tools'])) {
                $options['tools'][] = $webSearchTool;
            } else {
                $options['tools'] = [$webSearchTool];
            }
            
            // 初始化状态跟踪
            $status = [
                'isCompleted' => false,
                'allMessages' => [],
                'searchTokens' => 0,
                'finalResponse' => null,
                'usage' => null
            ];
            
            // 准备消息上下文
            $messages = [];
            
            // 添加系统消息
            $systemMessages = $this->getSystemMessages();
            if (!empty($systemMessages)) {
                foreach ($systemMessages as $systemMessage) {
                    $messages[] = $systemMessage;
                }
            }
            
            // 添加历史消息
            foreach ($this->history as $message) {
                $messages[] = $message;
            }
            
            // 添加当前查询
            $messages[] = ['role' => 'user', 'content' => $query];
            
            // 记录所有消息
            $status['allMessages'] = $messages;
            
            // 记录开始时间
            $startTime = microtime(true);
            $this->log("开始Web搜索请求: {$query}", 2);
            
            // 执行工具调用循环 - 直到得到最终回答
            while (!$status['isCompleted']) {
                // 准备请求参数
                $requestOptions = array_merge($options, [
                    'messages' => $messages,
                    'model' => $options['model'] ?? 'moonshot-v1-auto', // 推荐使用自动模型以适应Token变化
                ]);
                
                // 发送请求
                $this->log("发送API请求", 3);
                $response = $this->sendRequest($this->baseUrl . '/chat/completions', [
                    \GuzzleHttp\RequestOptions::JSON => $requestOptions,
                    \GuzzleHttp\RequestOptions::HEADERS => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json'
                    ]
                ]);
                
                // 解析响应
                $responseBody = $response->getBody()->__toString();
                $responseData = json_decode($responseBody, true);
                
                if (!isset($responseData['choices'][0])) {
                    throw new Exception("API返回的响应格式无效");
                }
                
                $choice = $responseData['choices'][0];
                $message = $choice['message'];
                $finishReason = $choice['finish_reason'] ?? '';
                
                // 处理工具调用
                if ($finishReason === 'tool_calls' && isset($message['tool_calls'])) {
                    $this->log("收到工具调用请求", 2);
                    
                    // 添加AI消息到历史
                    $messages[] = $message;
                    
                    // 处理每个工具调用
                    foreach ($message['tool_calls'] as $toolCall) {
                        $function = $toolCall['function'] ?? [];
                        $name = $function['name'] ?? '';
                        $arguments = json_decode($function['arguments'] ?? '{}', true);
                        $toolCallId = $toolCall['id'] ?? '';
                        
                        if ($name === '$web_search') {
                            // 记录搜索使用的Token
                            if (isset($arguments['usage']['total_tokens'])) {
                                $status['searchTokens'] = $arguments['usage']['total_tokens'];
                                $this->log("Web搜索消耗Token: " . $status['searchTokens'], 2);
                            }
                            
                            // 关键步骤：对于内置$web_search函数，直接返回原始参数
                            // 不需要实现搜索、解析等逻辑，MoonshotAI会处理
                            $toolResult = $arguments;
                            
                            // 添加工具响应到消息历史
                            $messages[] = [
                                'role' => 'tool',
                                'tool_call_id' => $toolCallId,
                                'name' => $name,
                                'content' => json_encode($toolResult)
                            ];
                        }
                    }
                } else {
                    // 搜索完成，获取最终回答
                    $status['isCompleted'] = true;
                    $status['finalResponse'] = $message;
                    
                    // 记录Token使用情况
                    if (isset($responseData['usage'])) {
                        $status['usage'] = $responseData['usage'];
                    }
                    
                    // 添加最终回答到历史
                    $this->updateHistory($message['content']);
                }
            }
            
            // 计算耗时
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            
            $this->log("Web搜索完成，耗时: {$duration}秒", 2);
            
            // 返回结果
            return [
                'content' => $status['finalResponse']['content'] ?? '',
                'search_tokens' => $status['searchTokens'],
                'usage' => $status['usage'],
                'duration' => $duration,
                'messages' => $status['allMessages']
            ];
        } catch (Exception $e) {
            $this->log("Web搜索错误: " . $e->getMessage(), 1);
            throw $e;
        }
    }

    /**
     * 基于文件进行问答
     * 
     * @param array|string $filePaths 文件路径或路径数组
     * @param string       $query     用户问题
     * @param array        $options   额外选项
     * @param bool|string  $useCache  是否使用缓存，字符串则作为缓存标签
     * @param int          $cacheTtl  缓存有效期(秒)，默认1小时
     * @return string 问答结果
     * @throws Exception|GuzzleException
     */
    public function fileQA(array|string $filePaths, string $query, array $options = [], bool|string $useCache = true, int $cacheTtl = 3600): string
    {
        $originalHistory = $this->history;
        
        try {
            // 验证API密钥
            $this->validateApiKey();
            
            // 处理单个文件路径的情况
            if (!is_array($filePaths)) {
                $filePaths = [$filePaths];
            }
            
            // 检查文件是否存在
            $this->validateFilePaths($filePaths);
            
            $this->log(['开始处理文件问答', 'files' => $filePaths, 'query' => $query], 1);
            
            // 获取所有文件内容并构建系统消息
            $systemMessages = $this->getFilesContentAsSystemMessages($filePaths, $useCache, $cacheTtl, $options);
            
            // 准备消息列表
            $messages = $this->prepareMessagesForFileQA($systemMessages, $originalHistory);
            
            // 设置默认选项和执行请求
            $finalOptions = $this->prepareFinalOptionsForFileQA($options);
            
            // 替换历史记录用于请求
            $this->history = $messages;
            
            // 发送请求
            $this->log(['准备发送文件问答请求', 'message_count' => count($this->history)], 1);
            $response = $this->moonshot($query, $finalOptions);
            
            return $response;
        } catch (Exception $e) {
            $this->log(['文件问答失败', 'error' => $e->getMessage()], 1);
            throw $e;
        } finally {
            // 无论成功还是失败，恢复原始历史
            $this->history = $originalHistory;
        }
    }

    /**
     * 验证文件路径是否存在
     * 
     * @param array $filePaths 文件路径数组
     * @throws Exception 如果任何文件不存在
     */
    private function validateFilePaths(array $filePaths): void
    {
        foreach ($filePaths as $path) {
            if (!file_exists($path)) {
                throw new Exception("文件不存在: " . $path);
            }
        }
    }

    /**
     * 获取所有文件内容并构建为系统消息
     *
     * @param array       $filePaths 文件路径数组
     * @param bool|string $useCache  是否使用缓存
     * @param int         $cacheTtl  缓存有效期
     * @param array       $options   选项
     * @return array 系统消息数组
     * @throws Exception|GuzzleException
     */
    private function getFilesContentAsSystemMessages(array $filePaths, bool|string $useCache, int $cacheTtl, array $options): array
    {
        // 使用缓存键前缀
        $cacheKeyPrefix = 'file_content_';
        $systemMessages = [];
        
        foreach ($filePaths as $filePath) {
            $fileHash = md5_file($filePath);
            $cacheKey = $cacheKeyPrefix . $fileHash;
            
            // 检查本地缓存
            $fileContent = null;
            if ($useCache) {
                $fileContent = $this->getCache($cacheKey);
            }
            
            // 如果没有缓存或缓存失效，则上传并获取内容
            if ($fileContent === null) {
                $fileContent = $this->processFileAndGetContent($filePath, $cacheKey, $cacheTtl, $useCache, $options);
            } else {
                $this->log(['使用缓存的文件内容', 'file' => $filePath], 1);
            }
            
            // 将文件内容添加为系统消息
            $systemMessages[] = [
                'role' => 'system',
                'content' => $fileContent
            ];
        }
        
        return $systemMessages;
    }

    /**
     * 处理文件并获取内容
     *
     * @param string      $filePath 文件路径
     * @param string      $cacheKey 缓存键
     * @param int         $cacheTtl 缓存有效期
     * @param bool|string $useCache 是否使用缓存
     * @param array       $options  选项
     * @return string 文件内容
     * @throws Exception|GuzzleException
     */
    private function processFileAndGetContent(string $filePath, string $cacheKey, int $cacheTtl, bool|string $useCache, array $options): string
    {
        $this->log(['文件内容缓存未命中，开始上传', 'file' => $filePath], 1);
        
        // 上传文件
        $uploadResult = $this->uploadFile($filePath);
        if (empty($uploadResult) || !isset($uploadResult['id'])) {
            throw new Exception("文件上传失败: " . $filePath);
        }
        
        $fileId = $uploadResult['id'];
        $this->log(['文件上传成功', 'file_id' => $fileId], 1);
        
        try {
            // 获取文件内容
            $fileContent = $this->retrieveFileContent($fileId);
            
            // 缓存文件内容
            if ($useCache && !empty($fileContent)) {
                $this->setCache($cacheKey, $fileContent, $cacheTtl);
            }
            
            // 清理已上传的文件（可选，根据最佳实践建议）
            if (isset($options['auto_delete_files']) && $options['auto_delete_files'] === true) {
                try {
                    $this->deleteFile($fileId);
                    $this->log(['已删除已处理的文件', 'file_id' => $fileId], 1);
                } catch (Exception $e) {
                    $this->log(['删除文件失败，但不影响主流程', 'error' => $e->getMessage()], 2);
                }
            }
            
            return $fileContent;
        } catch (Exception $e) {
            // 记录错误并重新抛出
            $this->log(['获取文件内容失败', 'file_id' => $fileId, 'error' => $e->getMessage()], 2);
            throw $e;
        }
    }

    /**
     * 准备文件问答的消息列表
     * 
     * @param array $systemMessages 系统消息
     * @param array $originalHistory 原始历史记录
     * @return array 准备好的消息列表
     */
    private function prepareMessagesForFileQA(array $systemMessages, array $originalHistory): array
    {
        $messages = [];
        
        // 添加所有文件内容作为system消息
        foreach ($systemMessages as $message) {
            $messages[] = $message;
        }
        
        // 添加默认系统提示，如果没有
        $hasSystemMessage = false;
        foreach ($originalHistory as $message) {
            if (isset($message['role']) && $message['role'] === 'system') {
                $hasSystemMessage = true;
                $messages[] = $message;
            }
        }
        
        // 如果没有系统消息，添加默认系统消息
        if (!$hasSystemMessage) {
            $messages[] = [
                'role' => 'system',
                'content' => '你是Kimi，由Moonshot AI提供的人工智能助手，你更擅长中文和英文的对话。你会为用户提供安全，有帮助，准确的回答。同时，你会拒绝一切涉及恐怖主义，种族歧视，黄色暴力等问题的回答。'
            ];
        }
        
        // 添加非系统消息的历史记录
        foreach ($originalHistory as $message) {
            if (!isset($message['role']) || $message['role'] !== 'system') {
                $messages[] = $message;
            }
        }
        
        return $messages;
    }

    /**
     * 准备文件问答的最终选项
     * 
     * @param array $options 用户选项
     * @return array 最终选项
     */
    private function prepareFinalOptionsForFileQA(array $options): array
    {
        // 设置默认选项
        $defaultOptions = [
            'model' => 'moonshot-v1-32k', // 使用具有较大上下文窗口的模型
            'temperature' => 0.3,
        ];
        
        // 合并选项，用户提供的选项优先
        return array_merge($defaultOptions, $options);
    }

    /**
     * 清理所有已上传的文件
     *
     * @return int 已删除的文件数量
     * @throws GuzzleException
     * @throws Exception
     */
    public function cleanupAllFiles(): int
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key必须提供");
            }
            
            $this->log('开始清理所有已上传文件', 1);
            
            // 获取文件列表
            $fileList = $this->listFiles();
            
            if (empty($fileList) || !isset($fileList['data'])) {
                $this->log('文件列表为空或格式无效', 2);
                return 0;
            }
            
            $deletedCount = 0;
            
            // 逐个删除文件
            foreach ($fileList['data'] as $file) {
                if (isset($file['id'])) {
                    try {
                        $this->deleteFile($file['id']);
                        $deletedCount++;
                        $this->log(['已删除文件', 'file_id' => $file['id']], 1);
                    } catch (Exception $e) {
                        $this->log(['删除文件失败', 'file_id' => $file['id'], 'error' => $e->getMessage()], 2);
                    }
                }
            }
            
            $this->log(['文件清理完成', 'deleted_count' => $deletedCount], 1);
            return $deletedCount;
        } catch (Exception $e) {
            $this->log(['清理文件失败', 'error' => $e->getMessage()], 1);
            throw $e;
        }
    }

    /**
     * 基于Context Caching进行文件问答，可显著降低重复提问的token消耗 .
     *
     * @param array|string $filePaths 文件路径或路径数组
     * @param string       $query     用户问题
     * @param string       $cacheTag  缓存标签，用于标识和引用缓存内容
     * @param array        $options   附加选项
     * @param int          $cacheTtl  缓存有效期(秒)，默认300秒
     * @return string 问答结果
     * @throws GuzzleException
     * @throws Exception
     */
    public function fileQAWithCache(array|string $filePaths, string $query, string $cacheTag, array $options = [], int $cacheTtl = 300): string
    {
        try {
            // 验证API密钥
            if (empty($this->apiKey)) {
                throw new Exception("API Key必须提供");
            }
            
            // 处理单个文件路径的情况
            if (!is_array($filePaths)) {
                $filePaths = [$filePaths];
            }
            
            // 检查文件是否存在
            foreach ($filePaths as $path) {
                if (!file_exists($path)) {
                    throw new Exception("文件不存在: " . $path);
                }
            }
            
            $this->log(['开始处理带缓存的文件问答', 'files' => $filePaths, 'cache_tag' => $cacheTag, 'query' => $query], 1);
            
            // 检查该标签的缓存是否存在
            $cacheExists = false;
            try {
                // {"tag":"tag-d090b8d970449edd8c161092475a0a8f","cache_id":"cache-ezo4aw5oc6di11gf76si","object":"cache_object.tag","owned_by":"","created_at":1743398000}
                $tagJsonInfo = $this->retrieveCacheTag($cacheTag);
                $tagInfo = json_decode($tagJsonInfo, true);
                if (isset($tagInfo['cache_id'])) {
                    $cacheExists = true;
                    $this->log(['找到已有缓存', 'cache_tag' => $cacheTag, 'cache_id' => $tagInfo['cache_id']], 1);
                }
            } catch (Exception $e) {
                // 缓存标签不存在，需要创建新缓存
                $this->log(['缓存标签不存在', 'cache_tag' => $cacheTag, 'error' => $e->getMessage()], 2);
            }
            
            // 如果缓存不存在，需要上传文件并创建缓存
            if (!$cacheExists) {
                $this->log(['需要创建新缓存', 'cache_tag' => $cacheTag], 1);
                
                // 准备系统消息
                $systemMessages = [];
                
                // 处理每个文件
                foreach ($filePaths as $filePath) {
                    $this->log(['处理文件', 'file' => $filePath], 1);
                    
                    // 上传文件
                    $uploadResult = $this->uploadFile($filePath);
                    if (empty($uploadResult) || !isset($uploadResult['id'])) {
                        throw new Exception("文件上传失败: " . $filePath);
                    }
                    
                    $fileId = $uploadResult['id'];
                    $this->log(['文件上传成功', 'file_id' => $fileId], 1);
                    
                    // 获取文件内容
                    $fileContent = $this->retrieveFileContent($fileId);
                    
                    // 将文件内容添加为系统消息
                    $systemMessages[] = [
                        'role' => 'system',
                        'content' => $fileContent
                    ];
                    
                    // 清理已上传的文件（可选，根据最佳实践建议）
                    if (isset($options['auto_delete_files']) && $options['auto_delete_files'] === true) {
                        try {
                            $this->deleteFile($fileId);
                            $this->log(['已删除已处理的文件', 'file_id' => $fileId], 1);
                        } catch (Exception $e) {
                            $this->log(['删除文件失败，但不影响主流程', 'error' => $e->getMessage()], 2);
                        }
                    }
                }
                
                // 创建缓存
                $cacheOptions = [
                    'model' => "moonshot-v1",  // 使用当前模型配置 固定为 moonshot-v1 
                    'messages' => $systemMessages,
                    'ttl' => $cacheTtl,
                    'tags' => [$cacheTag]
                ];
                
                $cacheResult = $this->createCache($systemMessages, [], $cacheOptions);
                
                if (empty($cacheResult) || !isset($cacheResult['id'])) {
                    throw new Exception(__FUNCTION__ ."创建缓存失败");
                }

                $this->createCacheTag($cacheTag, $cacheResult['id']);
                
                $this->log(['缓存创建成功', 'cache_id' => $cacheResult['id']], 1);
            }
            
            // 组合消息列表，包括缓存引用
            $originalHistory = $this->history;
            $this->history = [];
            
            // 添加缓存引用消息
            $this->history[] = [
                'role' => 'cache',
                'content' => "tag={$cacheTag};reset_ttl={$cacheTtl}"
            ];
            
            // 添加其他系统消息，排除文件内容
            $hasSystemMessage = false;
            foreach ($originalHistory as $message) {
                if (isset($message['role']) && $message['role'] === 'system') {
                    $hasSystemMessage = true;
                    $this->history[] = $message;
                }
            }
            
            // 如果没有系统消息，添加默认系统消息
            if (!$hasSystemMessage) {
                $this->history[] = [
                    'role' => 'system',
                    'content' => '你是Kimi，由Moonshot AI提供的人工智能助手，你更擅长中文和英文的对话。你会为用户提供安全，有帮助，准确的回答。同时，你会拒绝一切涉及恐怖主义，种族歧视，黄色暴力等问题的回答。'
                ];
            }
            
            // 添加非系统消息的历史记录
            foreach ($originalHistory as $message) {
                if (!isset($message['role']) || $message['role'] !== 'system') {
                    $this->history[] = $message;
                }
            }
            
            // 设置默认选项
            $defaultOptions = [
                'model' => 'moonshot-v1-32k', // 使用具有较大上下文窗口的模型
                'temperature' => 0.3,
            ];
            
            // 合并选项，用户提供的选项优先
            $finalOptions = array_merge($defaultOptions, $options);
            
            // 发送请求
            $this->log(['准备发送带缓存的文件问答请求', 'message_count' => count($this->history)], 1);
            $response = $this->moonshot($query, $finalOptions);
            
            // 恢复原始历史
            $this->history = $originalHistory;
            
            return $response;
        }catch (\GuzzleHttp\Exception\ClientException $e) {
            // 获取完整的响应内容
            $response = $e->getResponse();
            $fullErrorBody = $response->getBody()->getContents();
            $this->log(['完整错误信息', $fullErrorBody], 1);
            throw $e;
        }  catch (Exception $e) {
            $this->log(['带缓存的文件问答失败', 'error' => $e->getMessage()], 1);
            throw $e;
        }
    }

    /**
     * 创建上下文缓存
     *
     * @param array $messages 消息数组
     * @param array $tools 工具数组
     * @param array $options 选项
     * @return string 创建的缓存信息(JSON字符串)
     * @throws \Puge2016\MoonshotAiSdk\Exceptions\MoonshotException 当API请求失败或参数无效时
     */
    public function createCache(array $messages, array $tools = [], array $options = []): string
    {
        try {
            if (empty($this->apiKey)) {
                throw new Exception("API Key必须提供");
            }
            
            // 准备请求参数
            $requestData = [
                'model' => $options['model'] ?? "moonshot-v1",  // 默认使用 moonshot-v1
                'messages' => $messages,
            ];
            
            // 添加可选参数
            if (!empty($tools)) {
                $requestData['tools'] = $tools;
            }
            
            // 添加TTL参数
            if (isset($options['ttl'])) {
                $requestData['ttl'] = (int)$options['ttl'];
            }
            
            // 添加标签
            if (isset($options['tags']) && is_array($options['tags'])) {
                $requestData['tags'] = $options['tags'];
            }

            if (isset($options['name'])) {
                $requestData['name'] = $options['name'];
            }
    
            if (isset($options['metadata'])) {
                $requestData['metadata'] = $options['metadata'];
            }
    
            if (isset($options['expired_at']) && !isset($options['ttl'])) {
                $requestData['expired_at'] = $options['expired_at'];
            }
    
            if (isset($options['description'])) {
                $requestData['description'] = $options['description'];
            }
            
            // 发送请求
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            $response = $client->post("{$this->baseUrl}/caching", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestData,
            ]);
            
            $responseBody = $response->getBody()->getContents();
            // 尝试解析JSON以验证响应格式正确
            $result = json_decode($responseBody, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("响应解析失败: {$responseBody}");
            }
            
            return $responseBody;
            
        } catch (GuzzleException $e) {
            $this->log([__METHOD__ .' 创建缓存失败', 'error' => $e->getMessage()], 2);
            $this->log($e->getTraceAsString(), 1);
            throw Exceptions\ErrorHandler::handleApiError($e);
        } catch (Exception $e) {
            $this->log([__METHOD__ .' 创建缓存失败', 'error' => $e->getMessage()], 2);
            throw Exceptions\ErrorHandler::createError(
                __METHOD__ ." 创建缓存失败: " . $e->getMessage(), 
                0, 
                'cache_create_error', 
                null, 
                $e
            );
        }
    }

}