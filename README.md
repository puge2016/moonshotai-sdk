# MoonshotAI SDK

<p align="center">
  <img src="https://moonshot.cn/favicon.ico" width="100" alt="MoonshotAI Logo"/>
</p>

<p align="center">
  <a href="https://packagist.org/packages/puge2016/moonshotai-sdk"><img src="https://img.shields.io/packagist/v/puge2016/moonshotai-sdk.svg" alt="Latest Stable Version"></a>
  <a href="https://packagist.org/packages/puge2016/moonshotai-sdk"><img src="https://img.shields.io/packagist/dt/puge2016/moonshotai-sdk.svg" alt="Total Downloads"></a>
  <a href="https://packagist.org/packages/puge2016/moonshotai-sdk"><img src="https://img.shields.io/packagist/l/puge2016/moonshotai-sdk.svg" alt="License"></a>
</p>

## 简介

MoonshotAI SDK 是一个功能全面的 PHP 客户端库，专为与 MoonshotAI API 进行无缝集成而设计。该 SDK 封装了与 MoonshotAI 服务通信的复杂性，提供了简洁而强大的接口，让您能够轻松地在 PHP 项目中集成 AI 能力。

## 特性一览

- 🤖 **文本对话** - 支持单轮和多轮对话，自动维护会话上下文
- 👁️ **图像理解** - 单图和多图分析，支持本地图片和Base64编码
- 🔧 **工具调用** - 强大的函数调用功能，支持自定义工具定义和执行
- 🌐 **网络搜索** - 内置网络搜索能力，无需实现搜索逻辑
- 📄 **文件处理** - 文件上传、管理和内容分析
- 🧠 **高级功能** - 支持流式输出、上下文缓存、Token计算等
- 💼 **账户管理** - 查询余额、支持多API密钥管理
- 🛡️ **健壮性** - 完善的错误处理、自动重试机制和日志记录

## 安装

```bash
composer require puge2016/moonshotai-sdk
```

## 快速开始

### 基础用法

```php
<?php
require 'vendor/autoload.php';

use Puge2016\MoonshotAiSdk\MoonshotAI;

// 创建实例并设置API密钥
$moonshot = new MoonshotAI('my-session', 'moonshot-v1-8k');
$moonshot->setApiKey('your-api-key');

// 设置日志处理器（可选）
$moonshot->setLogHandler(function($message, $level) {
    // 自定义日志处理逻辑
    $levelNames = ['DEBUG', 'INFO', 'WARN', 'ERROR', 'FATAL'];
    $levelName = $levelNames[$level] ?? 'UNKNOWN';
    error_log("[{$levelName}] " . (is_array($message) ? json_encode($message) : $message));
});

// 发送一条消息并获取响应
$response = $moonshot->moonshot('你好，请介绍一下自己');
echo $response;
```

### 多轮对话

```php
// 创建带会话标识的实例
$moonshot = new MoonshotAI('conversation-123', 'moonshot-v1-8k');
$moonshot->setApiKey('your-api-key');

// 第一轮对话
$response1 = $moonshot->moonshot('你好，请简单介绍一下墨子');
echo "AI: " . $response1 . "\n\n";

// 第二轮对话（自动保持上下文）
$response2 = $moonshot->moonshot('他的哪些思想对现代社会有借鉴意义？');
echo "AI: " . $response2 . "\n\n";

// 如需清除对话历史
// $moonshot->clearHistory();
```

## 核心功能

### 1. 图像理解 (Vision)

```php
// 初始化时指定适合的Vision模型
$moonshot = new MoonshotAI('vision-task', 'moonshot-v1-8k');
$moonshot->setApiKey('your-api-key');
$moonshot->setLogHandler(function($message, $level) {
    // 日志处理逻辑
});

// 方法一：单张图片分析
$response = $moonshot->analyzeImage('/path/to/image.jpg', '描述这张图片中的内容');
echo $response;

// 方法二：多张图片分析（更接近实际测试用例）
$images = [
    '/path/to/first/image.jpg',
    '/path/to/second/image.jpg'
];
$response = $moonshot->analyzeImage($images, '请分析这些图片的内容');
echo $response;

// 方法三：使用base64编码的图片
$imageBase64 = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABA...';
$response = $moonshot->analyzeImage($imageBase64, '这张图片中有什么？');
echo $response;
```

### 2. 工具调用 (Function Calling)

```php
// 初始化
$moonshot = new MoonshotAI('tools-session', 'moonshot-v1-8k');
$moonshot->setApiKey('your-api-key');

// 定义工具
$tools = [
    [
        "type" => "function",
        "function" => [
            "name" => "getWeather",
            "description" => "获取指定城市的天气信息",
            "parameters" => [
                "type" => "object",
                "properties" => [
                    "location" => [
                        "type" => "string",
                        "description" => "城市名称"
                    ],
                    "unit" => [
                        "type" => "string",
                        "enum" => ["celsius", "fahrenheit"],
                        "description" => "温度单位，默认为摄氏度"
                    ]
                ],
                "required" => ["location"]
            ]
        ]
    ]
];

// 自动工具调用流程（推荐）
$toolExecutor = function($name, $arguments) {
    if ($name === 'getWeather') {
        $location = $arguments['location'] ?? '北京';
        return json_encode([
            'location' => $location,
            'temperature' => '23°C',
            'condition' => '晴朗',
            'humidity' => '45%',
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }
    return "未知工具";
};

$response = $moonshot->executeToolCall("今天上海天气怎么样？", $tools, $toolExecutor);
echo $response['content'];
```

### 3. 文件处理与分析

```php
// 上传文件
$fileInfo = $moonshot->uploadFile('/path/to/document.pdf');
echo "文件ID: " . $fileInfo['id'] . "\n";

// 提取文件内容
$content = $moonshot->retrieveFileContent($fileInfo['id']);
echo "文件内容: " . $content . "\n";

// 基于文件内容的问答（File QA）
$response = $moonshot->fileQA('/path/to/document.pdf', '这份文档的主要内容是什么？');
echo "AI回答: " . $response;

// 删除文件
$moonshot->deleteFile($fileInfo['id']);
```

### 4. 网络搜索 (Web Search)

```php
// 初始化 - 指定会话标识和合适的模型
$moonshot = new MoonshotAI('search-session', 'moonshot-v1-8k');
$moonshot->setApiKey('your-api-key');

// 可选：设置日志处理器
$moonshot->setLogHandler(function($message, $level) {
    // 日志处理逻辑
});

// 执行网络搜索并获取结果
try {
    // 简单搜索示例 - 直接搜索日期和天气信息
    $result = $moonshot->webSearch('请搜索今天是几月几号和北京天气，并告诉我。');
    
    // 输出AI回答
    echo "AI回答：\n" . $result['content'] . "\n\n";
    
    // 显示Token使用情况和性能数据
    echo "搜索消耗Token: " . $result['search_tokens'] . "\n";
    echo "总计消耗Token: " . ($result['usage']['total_tokens'] ?? '未知') . "\n";
    echo "请求耗时: " . $result['duration'] . "秒\n";
    
    // 使用自定义选项的更复杂搜索
    $options = [
        'model' => 'moonshot-v1-128k',  // 使用大容量模型处理更复杂的搜索结果
        'temperature' => 0.3,           // 控制回答的创造性
    ];
    
    $result = $moonshot->webSearch('请对比分析中国和美国的AI政策', $options);
    echo $result['content'];
} catch (\Exception $e) {
    echo "搜索错误: " . $e->getMessage();
}
```

### 5. 流式输出

```php
// 流式输出回调
$chunkCallback = function($chunk) {
    echo $chunk;
    ob_flush();
    flush();
};

// 完成回调
$completeCallback = function($allContent, $usage) {
    echo "\n\n完成！总共使用了 {$usage['total_tokens']} 个 tokens";
};

// 执行流式聊天
$moonshot->streamChat(
    '请给我讲一个关于人工智能的故事', 
    $chunkCallback, 
    $completeCallback
);
```

## 高级功能

### 自定义日志和缓存

```php
// 设置日志处理器
$moonshot->setLogHandler(function($message, $level) {
    $levelNames = ['DEBUG', 'INFO', 'WARN', 'ERROR', 'FATAL'];
    $levelName = $levelNames[$level] ?? 'UNKNOWN';
    
    $logMessage = date('Y-m-d H:i:s') . " [{$levelName}] " . 
                 (is_array($message) ? json_encode($message) : $message);
    
    file_put_contents('moonshot_sdk.log', $logMessage . PHP_EOL, FILE_APPEND);
});

// 设置缓存处理器
$moonshot->setCacheHandler(function($action, $key, $value = null, $ttl = 300) {
    static $cache = [];
    
    if ($action === 'get') {
        return $cache[$key] ?? null;
    } elseif ($action === 'set') {
        $cache[$key] = $value;
        return true;
    }
    return false;
});
```

### 会话导入/导出

```php
// 导出当前会话状态
$sessionData = $moonshot->exportSession();
file_put_contents('session_backup.json', json_encode($sessionData));

// 导入会话状态
$savedSession = json_decode(file_contents('session_backup.json'), true);
$newMoonshot = new MoonshotAI();
$newMoonshot->setApiKey('your-api-key')
           ->importSession($savedSession);
```

### 系统消息设置

```php
// 设置自定义系统消息
$moonshot->setSystemMessage('你是一个专注于医疗健康领域的AI助手，请用专业但通俗易懂的语言回答用户的健康相关问题。');

// 获取当前系统消息
$systemMessages = $moonshot->getSystemMessages();
```

### 自动重试配置

```php
// 设置最大重试5次，初始延迟2秒，最大延迟60秒
$moonshot->setRetryConfig(5, 2, 60);

// 禁用自动重试
$moonshot->setRetryConfig(0);
```

## 错误处理

SDK 提供了统一的错误处理机制，通过 `MoonshotException` 类可以轻松处理各种异常情况：

```php
use Puge2016\MoonshotAiSdk\MoonshotAI;
use Puge2016\MoonshotAiSdk\Exceptions\MoonshotException;

try {
    $moonshot = new MoonshotAI('error-handling-demo');
    $moonshot->setApiKey('your-api-key');
    
    $response = $moonshot->moonshot('分析当前全球经济形势');
    echo $response;
    
} catch (MoonshotException $e) {
    echo "错误类型: " . $e->getErrorType() . "\n";
    echo "错误代码: " . $e->getCode() . "\n";
    echo "错误信息: " . $e->getMessage() . "\n";
    
    // 根据错误类型执行不同操作
    if ($e->isAuthError()) {
        echo "认证错误：请检查API密钥是否正确\n";
    } elseif ($e->isRateLimitError()) {
        echo "请求频率限制：等待一段时间后重试\n";
        sleep(5);
        // 重试逻辑...
    } elseif ($e->isContentFilterError()) {
        echo "内容安全过滤：请修改您的提问内容\n";
    }
    
} catch (\Exception $e) {
    echo "其他错误: " . $e->getMessage() . "\n";
}
```

## 示例项目

查看 `examples` 目录获取更多使用示例：

- `basic_chat.php` - 基础对话使用示例
- `vision_demo.php` - 图像理解示例
- `tool_calling.php` - 工具调用示例
- `web_search.php` - 网络搜索示例
- `file_operations.php` - 文件操作示例
- `error_handling.php` - 错误处理示例
- `streaming.php` - 流式输出示例

## 版本记录

查看 [CHANGELOG.md](CHANGELOG.md) 了解版本更新详情。

## 贡献指南

欢迎提交 Pull Requests 和 Issues 帮助改进此SDK。请确保您的代码符合现有的代码风格并通过所有测试。

## 许可证

MIT License - 查看 [LICENSE](LICENSE) 文件了解更多详情。 