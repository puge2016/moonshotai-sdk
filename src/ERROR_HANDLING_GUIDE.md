# MoonshotAI SDK 错误处理规范

本文档提供了在 MoonshotAI SDK 中统一错误处理的规范和实现指南。

## 错误处理目标

1. **统一异常类型**：所有公开方法抛出的异常都应是 `MoonshotException` 类型
2. **结构化错误信息**：提供详细的错误类型和原始响应数据
3. **错误分类一致**：按功能域和错误类型分类错误
4. **友好错误信息**：提供清晰、有用的错误消息
5. **精确错误位置**：提供具体的错误发生位置，方便调试和问题定位

## 错误处理模式

所有 API 请求方法应遵循以下错误处理模式：

```php
public function someApiMethod(): mixed
{
    try {
        // 参数验证
        if (empty($requiredParam)) {
            throw new Exception("Required parameter is missing");
        }
        
        // API 请求逻辑...
        $client = new Client();
        $response = $client->request(...);
        
        // 处理响应...
        return $result;
        
    } catch (GuzzleException $e) {
        // API 请求异常处理
        $this->log(['方法名称失败', $e->getMessage()], 2);
        throw ErrorHandler::handleApiError($e);
    } catch (Exception $e) {
        // 其他异常处理
        $this->log(['方法名称失败', $e->getMessage()], 2);
        throw ErrorHandler::createError("方法名称失败: " . $e->getMessage(), 0, 'error_type');
    }
}
```

## 错误类型标识

应使用一致的错误类型标识，建议的命名规则：

- `{domain}_{action}_error`：例如 `file_upload_error`、`chat_completion_error`

常见错误类型：

| 错误类型标识 | 描述 |
|------------|------|
| `authentication_error` | API 密钥无效或认证失败 |
| `permission_error` | 权限不足 |
| `account_inactive_error` | 账户未激活或已被禁用 |
| `rate_limit_error` | 请求频率超限 |
| `invalid_parameter` | 无效参数 |
| `content_filter` | 内容被安全过滤 |
| `context_length` | 上下文长度超限 |
| `file_upload_error` | 文件上传失败 |
| `file_retrieve_error` | 获取文件失败 |
| `file_content_error` | 获取文件内容失败 |
| `file_delete_error` | 删除文件失败 |
| `files_cleanup_error` | 清理文件失败 |
| `chat_completion_error` | 对话请求失败 |
| `vision_error` | 图像理解失败 |
| `tool_call_error` | 工具调用失败 |
| `connection_error` | 连接失败 |
| `server_error` | 服务器错误 |
| `cache_create_error` | 创建缓存失败 |
| `cache_retrieve_error` | 获取缓存失败 |
| `cache_update_error` | 更新缓存失败 |
| `cache_delete_error` | 删除缓存失败 |

## 错误严重级别

在记录日志时使用一致的错误级别：

| 级别 | 数值 | 使用场景 |
|-----|-----|---------|
| DEBUG | 0 | 详细调试信息 |
| INFO | 1 | 一般信息性消息 |
| WARN | 2 | 警告但不影响主要功能 |
| ERROR | 3 | 错误阻止某个操作完成 |
| FATAL | 4 | 严重错误导致应用不可用 |

## 错误位置追踪

MoonshotAI SDK 现在支持精确的错误位置追踪，可以帮助您快速定位错误发生的具体代码位置。

### 错误位置信息

当捕获到 `MoonshotException` 异常时，可以使用以下方法获取错误位置信息：

```php
try {
    $response = $moonshot->moonshot('你好');
} catch (MoonshotException $e) {
    // 获取格式化的错误位置信息
    echo $e->getFormattedErrorLocation();
    // 输出: "在文件 /path/to/file.php 的第 123 行（ClassName->methodName()）"
    
    // 获取错误位置的详细数组信息
    $location = $e->getErrorLocation();
    echo "文件: " . $location['file'] . "\n";
    echo "行号: " . $location['line'] . "\n";
    echo "函数: " . $location['function'] . "\n";
    
    // 获取更详细的堆栈信息（默认显示3层）
    echo $e->getDetailedStackInfo();
    // 或者指定显示更多层数
    echo $e->getDetailedStackInfo(5);
    
    // 获取完整的错误信息（包括错误消息和位置）
    echo $e->getFullErrorInfo();
}
```

### 错误追踪最佳实践

1. **在生产环境中记录详细错误信息**：

   ```php
   try {
       $result = $moonshot->moonshot($query);
   } catch (MoonshotException $e) {
       // 记录详细错误信息
       logger()->error('MoonshotAI 错误', [
           'message' => $e->getMessage(),
           'code' => $e->getCode(),
           'type' => $e->getErrorType(),
           'location' => $e->getFormattedErrorLocation(),
           'stack' => $e->getDetailedStackInfo()
       ]);
       
       // 给用户显示友好错误消息
       return "处理请求时发生错误，请稍后再试";
   }
   ```

2. **在开发环境中显示完整错误信息**：

   ```php
   try {
       $result = $moonshot->moonshot($query);
   } catch (MoonshotException $e) {
       if (app()->environment('local', 'development')) {
           // 开发环境显示完整错误信息
           dd($e->getFullErrorInfo(), $e->getDetailedStackInfo());
       } else {
           // 生产环境只记录日志
           logger()->error($e->getFullErrorInfo());
           return "处理请求时发生错误，请稍后再试";
       }
   }
   ```

3. **错误调试助手函数示例**：

   ```php
   function debugMoonshotError(MoonshotException $e): void
   {
       echo "=== MoonshotAI 错误调试信息 ===\n";
       echo "错误消息: " . $e->getMessage() . "\n";
       echo "错误类型: " . $e->getErrorType() . "\n";
       echo "错误代码: " . $e->getCode() . "\n";
       echo "错误位置: " . $e->getFormattedErrorLocation() . "\n";
       echo "调用栈:\n" . $e->getDetailedStackInfo() . "\n";
       
       if ($e->getResponseData()) {
           echo "API 响应数据:\n";
           print_r($e->getResponseData());
       }
       
       echo "===============================\n";
   }
   ```

## 示例

### 文件操作错误处理

```php
public function uploadFile(string $filePath, string $purpose = 'file-extract'): array
{
    try {
        if (empty($this->apiKey)) {
            throw new Exception("API Key is required");
        }
        
        if (!file_exists($filePath)) {
            throw new Exception("File not found: {$filePath}");
        }
        
        // API 请求逻辑...
        
    } catch (GuzzleException $e) {
        $this->log(['文件上传失败', $e->getMessage()], 2);
        throw ErrorHandler::handleApiError($e);
    } catch (Exception $e) {
        $this->log(['文件上传失败', $e->getMessage()], 2);
        throw ErrorHandler::createError("文件上传失败: " . $e->getMessage(), 0, 'file_upload_error');
    }
}
```

### 对话请求错误处理

```php
public function moonshot(string $query, array $options = []): string
{
    try {
        $this->validateApiKey();
        $query = $this->sanitizeQuery($query);
        
        // API 请求逻辑...
        
    } catch (GuzzleException $e) {
        $this->log(['对话请求失败', $e->getMessage()], 2);
        throw ErrorHandler::handleApiError($e);
    } catch (Exception $e) {
        $this->log(['对话请求失败', $e->getMessage()], 2);
        throw ErrorHandler::createError("对话请求失败: " . $e->getMessage(), 0, 'chat_completion_error');
    }
}
```

### 账户激活错误处理

```php
try {
    $response = $moonshot->moonshot('你好');
    echo $response;
} catch (MoonshotException $e) {
    if ($e->isAccountInactiveError()) {
        echo "账户未激活: " . $e->getMessage();
        echo "请联系管理员激活您的账户或检查账户状态";
        // 可以添加特定的账户激活处理逻辑，如重定向到账户激活页面
    } elseif ($e->isAuthError()) {
        echo "认证错误: " . $e->getMessage();
    } elseif ($e->isRateLimitError()) {
        echo "请求频率限制: " . $e->getMessage();
        sleep(5); // 等待后重试
    } else {
        echo "其他错误: " . $e->getFormattedMessage();
    }
    
    // 记录错误信息供调试
    logger()->error("MoonshotAI 错误", [
        'error_type' => $e->getErrorType(),
        'error_code' => $e->getCode(),
        'error_message' => $e->getMessage(),
        'response_data' => $e->getResponseData()
    ]);
}
```

### 缓存操作错误处理示例

```php
// 在应用代码中
try {
    $moonshot = new MoonshotAI();
    $moonshot->setApiKey('your-api-key');
    
    // 创建缓存
    $cacheOptions = [
        'ttl' => 3600,
        'tags' => ['conversation-123']
    ];
    
    $messages = [
        ['role' => 'system', 'content' => '你是一个智能助手'],
        ['role' => 'user', 'content' => '你好，请介绍一下自己']
    ];
    
    $cacheResponse = $moonshot->createCache($messages, [], $cacheOptions);
    $cacheData = json_decode($cacheResponse, true);
    $cacheId = $cacheData['id'] ?? null;
    
    if ($cacheId) {
        echo "缓存创建成功，ID: " . $cacheId;
    } else {
        echo "缓存创建成功，但未返回ID";
    }
    
} catch (MoonshotException $e) {
    // 处理特定类型的错误
    if ($e->isAccountInactiveError()) {
        echo "账户未激活错误：" . $e->getMessage() . "\n";
        echo "请联系管理员激活您的账户\n";
        
        // 记录详细的错误位置信息
        logger()->error("账户未激活错误", [
            'error_location' => $e->getFormattedErrorLocation(),
            'error_stack' => $e->getDetailedStackInfo(),
            'error_data' => $e->getResponseData()
        ]);
    } else {
        // 使用MoonshotAI的调试辅助方法
        MoonshotAI::debugError($e);
        
        // 返回友好的错误信息给用户
        echo "缓存操作失败，请稍后再试";
    }
} catch (Exception $e) {
    // 处理其他类型的异常
    echo "发生错误: " . $e->getMessage();
}
```

在这个示例中：

1. 我们尝试创建一个缓存
2. 使用 `try-catch` 块捕获可能的 `MoonshotException`
3. 特别处理账户未激活错误，提供友好的用户提示
4. 使用 `getFormattedErrorLocation()` 和 `getDetailedStackInfo()` 获取详细的错误位置信息
5. 记录错误位置和调用栈，以便开发人员调试

这样即使在生产环境中，也能准确知道错误发生在代码的哪个位置。

## 客户端错误处理

使用 SDK 的客户端代码可以这样处理错误：

```php
try {
    $response = $moonshot->moonshot('你好');
    echo $response;
} catch (MoonshotException $e) {
    // 使用静态辅助方法快速调试错误
    // 这会打印完整的错误信息，包括错误消息、错误类型、错误代码、错误位置和调用栈
    MoonshotAI::debugError($e);
    
    // 或者获取错误信息字符串而不是直接输出
    $errorInfo = MoonshotAI::debugError($e, true);
    logger()->error($errorInfo);
    
    // 也可以手动处理特定类型的错误
    if ($e->isAuthError()) {
        echo "认证错误: " . $e->getMessage();
    } elseif ($e->isRateLimitError()) {
        echo "请求频率限制: " . $e->getMessage();
        sleep(5); // 等待后重试
    } elseif ($e->isContentFilterError()) {
        echo "内容过滤: " . $e->getMessage();
    } elseif ($e->isAccountInactiveError()) {
        echo "账户未激活: " . $e->getMessage();
        echo "请联系管理员激活您的账户";
    } else {
        echo "其他错误: " . $e->getFormattedMessage();
    }
    
    // 打印错误发生的位置
    echo "错误位置: " . $e->getFormattedErrorLocation();
    
    // 获取原始响应数据进行调试
    $responseData = $e->getResponseData();
    if ($responseData) {
        print_r($responseData);
    }
}
```

## 使用错误位置信息进行调试

下面是一个更完整的错误处理和调试流程示例：

```php
try {
    $moonshot = new MoonshotAI();
    $moonshot->setApiKey('您的API密钥');
    $response = $moonshot->moonshot('你好');
    echo $response;
} catch (MoonshotException $e) {
    // 在开发环境中显示详细错误信息
    if (defined('APP_DEBUG') && APP_DEBUG) {
        echo "<h2>MoonshotAI 错误</h2>";
        echo "<pre>";
        // 使用内置的调试助手方法
        MoonshotAI::debugError($e);
        echo "</pre>";
        exit;
    } 
    
    // 在生产环境中记录错误并显示友好消息
    else {
        // 记录错误到日志
        error_log("MoonshotAI 错误: " . $e->getFullErrorInfo());
        
        // 返回友好的错误消息
        if ($e->isAccountInactiveError()) {
            echo "您的账户未激活，请联系管理员。";
        } elseif ($e->isAuthError()) {
            echo "认证失败，请检查您的API密钥设置。";
        } elseif ($e->isRateLimitError()) {
            echo "请求过于频繁，请稍后再试。";
        } else {
            echo "处理您的请求时发生错误，请稍后再试。";
        }
    }
}
```

## 需要更新的方法列表

以下是 SDK 中需要更新错误处理逻辑的方法列表：

1. ✅ `listFiles()`
2. ✅ `retrieveFile(string $fileId)`
3. ✅ `retrieveFileContent(string $fileId)`
4. ✅ `uploadFile(string $filePath, string $purpose)`
5. ✅ `deleteFile(string $fileId)`
6. ✅ `cleanMoonshot()`
7. ✅ `moonshot(string $query, array $options)`
8. ✅ `createCache(array $messages, array $tools, array $options)`
9. `retrieveCache(string $cacheId)`
10. `updateCache(string $cacheId, array $options)`
11. `deleteCache(string $cacheId)`
12. `createCacheTag(string $tagName, string $cacheId)`
13. `retrieveCacheTag(string $tagName)`
14. `deleteCacheTag(string $tagName)`
15. `toolCall(string $query, array $tools, array $options)`
16. `executeToolCall(string $query, array $tools, callable $toolExecutor, array $options)`
17. `vision(mixed $messages, array $options)`
18. `analyzeImage(array|string $imagePathOrPaths, string $prompt, array $options)`
19. 等等...

## 注意事项

1. 所有公开方法的 PHPDoc 注释中应将 `@throws Exception` 和 `@throws GuzzleException` 替换为 `@throws MoonshotException`
2. 内部私有方法可以继续抛出原始异常类型，但应由调用它的公开方法捕获并转换
3. 确保所有错误日志包含足够的上下文信息，便于问题诊断
4. 不要在错误消息中包含敏感信息（如 API 密钥）
5. 对于账户激活错误，建议提供清晰的错误消息和解决建议