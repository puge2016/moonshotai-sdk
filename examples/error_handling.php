<?php
/**
 * MoonshotAI SDK 错误处理示例
 * 
 * 本示例展示了如何使用 MoonshotAI SDK 的错误处理功能，包括：
 * - 捕获和处理常见 API 错误
 * - 使用重试机制处理临时性错误
 * - 了解不同类型的错误及其处理方法
 * - 优雅地恢复错误状态
 */

require_once __DIR__ . '/../../../autoload.php';

use Puge2016\MoonshotAiSdk\MoonshotAI;
use Puge2016\MoonshotAiSdk\Exceptions\MoonshotException;

// 你的API密钥
$apiKey = 'your_api_key_here';

// 创建MoonshotAI实例
$moonshotAI = new MoonshotAI('error-handling-example', 'moonshot-v1-8k', $apiKey);

// 设置自定义的日志处理器
$moonshotAI->setLogHandler(function($message, $level) {
    $levelNames = ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];
    $levelName = $levelNames[$level] ?? 'UNKNOWN';
    
    // 格式化输出时间
    $timestamp = date('Y-m-d H:i:s');
    
    // 将日志消息格式化为字符串
    if (is_array($message)) {
        $formattedMessage = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        $formattedMessage = $message;
    }
    
    echo "[$timestamp] [$levelName] $formattedMessage\n";
});

// 设置重试配置 - 最多重试3次，初始延迟为1秒，最大延迟为10秒，使用抖动算法
$moonshotAI->setRetryConfig(3, 1, 10, true);

/**
 * 示例1: 捕获和处理常见API错误
 */
function example1($moonshotAI) {
    echo "\n=== 示例1: 捕获和处理常见API错误 ===\n";
    
    try {
        // 故意使用过长的提示文本来触发上下文长度错误
        $longPrompt = str_repeat("这是一个非常长的文本，用于测试上下文长度限制。", 1000);
        echo "发送长度约为 " . strlen($longPrompt) . " 字符的文本...\n";
        
        $result = $moonshotAI->moonshot($longPrompt);
        echo "成功获取回复（意外情况）: " . substr($result, 0, 100) . "...\n";
    } catch (MoonshotException $e) {
        echo "\n捕获到MoonshotException:\n";
        echo "错误类型: " . $e->getErrorType() . "\n";
        echo "错误消息: " . $e->getMessage() . "\n";
        echo "错误代码: " . $e->getCode() . "\n";
        
        // 根据错误类型执行不同的处理
        if ($e->isContextLengthError()) {
            echo "\n处理上下文长度错误:\n";
            echo "- 检测到文本过长，超出模型最大上下文窗口\n";
            echo "- 尝试截断输入文本到合适长度...\n";
            
            // 实际应用中的截断逻辑示例
            $truncatedText = substr($longPrompt, 0, 2000);
            echo "- 截断后文本长度: " . strlen($truncatedText) . " 字符\n";
            
            // 注意：此处不实际发送截断后的文本，以避免真实API调用
            echo "- 使用截断后的文本重新发送请求...\n";
        } elseif ($e->isContentFilterError()) {
            echo "\n处理内容过滤错误:\n";
            echo "- 检测到输入或输出内容可能包含敏感信息\n";
            echo "- 建议修改请求内容，避免违反内容政策\n";
        } elseif ($e->isRateLimitError()) {
            echo "\n处理速率限制错误:\n";
            echo "- 检测到请求频率过高\n";
            echo "- 等待一段时间后重试，或减少请求频率\n";
        }
    } catch (Exception $e) {
        echo "\n捕获到一般Exception:\n";
        echo "错误消息: " . $e->getMessage() . "\n";
    }
}

/**
 * 示例2: 使用错误恢复和重试机制
 */
function example2($moonshotAI) {
    echo "\n=== 示例2: 使用错误恢复和重试机制 ===\n";
    
    try {
        // 测试连接是否正常
        $connectionSuccess = $moonshotAI->testConnection();
        if (!$connectionSuccess) {
            echo "连接测试失败，但继续执行示例...\n";
        } else {
            echo "连接测试成功\n";
        }
        
        // 设置系统信息
        $moonshotAI->setSystemMessage("你是一个幽默的助手，总是用轻松愉快的方式回答问题。");
        
        // 模拟可能出现错误的情况
        echo "发送请求，模拟网络波动情况...\n";
        
        // 在实际应用中，网络错误会自动触发重试
        $query = "给我讲个笑话";
        $response = $moonshotAI->moonshot($query);
        
        echo "成功获取回复: " . $response . "\n";
        
    } catch (MoonshotException $e) {
        // 处理已知的API错误
        echo "错误: " . $e->getMessage() . "\n";
        
        // 检查是否为网络或服务器错误（通常可重试）
        if ($e->getCode() == 429 || $e->getCode() >= 500) {
            echo "这是一个可重试的错误（状态码 " . $e->getCode() . "），SDK已自动进行了重试\n";
        } else {
            echo "这是一个不可重试的错误，需要修改请求后重新发送\n";
        }
    } catch (Exception $e) {
        echo "未预期的错误: " . $e->getMessage() . "\n";
    }
}

/**
 * 示例3: 模拟不同类型的错误并展示错误信息格式化
 */
function example3($moonshotAI) {
    echo "\n=== 示例3: 错误信息格式化 ===\n";
    
    // 保存原始API密钥
    $originalApiKey = $moonshotAI->apiKey;
    
    try {
        // 1. 认证错误
        echo "模拟认证错误:\n";
        $moonshotAI->setApiKey('invalid_api_key');
        
        // 尝试执行操作（不会真正执行，因为API密钥无效）
        echo "尝试使用无效的API密钥发送请求...\n";
        
        // 2. 假设API限流错误
        echo "\n模拟API限流错误:\n";
        echo "429 Too Many Requests - 您的请求过于频繁，请稍后再试\n";
        echo "建议处理方式: 使用指数退避策略，SDK自动处理重试\n";
        
        // 3. 假设服务器错误
        echo "\n模拟服务器错误:\n";
        echo "500 Internal Server Error - 服务器内部错误\n";
        echo "建议处理方式: 等待一段时间后重试，或联系支持团队\n";
        
        // 4. 假设内容策略违规
        echo "\n模拟内容策略违规:\n";
        echo "400 Bad Request - 您的请求包含违反内容策略的内容\n";
        echo "建议处理方式: 修改请求内容，确保符合内容政策\n";
        
    } catch (MoonshotException $e) {
        // 此处捕获到实际的认证错误
        echo "\n捕获到实际错误:\n";
        echo $moonshotAI->getFormattedError($e, true) . "\n";
    } finally {
        // 恢复原始API密钥
        $moonshotAI->setApiKey($originalApiKey);
        echo "\n已恢复原始API密钥\n";
    }
}

/**
 * 示例4: 优雅处理错误并恢复
 */
function example4($moonshotAI) {
    echo "\n=== 示例4: 优雅处理错误并恢复 ===\n";
    
    // 定义一个函数，模拟可能出错的操作
    $processQuery = function($query) use ($moonshotAI) {
        // 根据查询内容，模拟不同的错误情况
        if (strpos($query, 'error') !== false) {
            throw new Exception("模拟的执行错误: " . $query);
        }
        return "处理成功: " . $query;
    };
    
    // 准备一系列查询，包括正常和可能导致错误的
    $queries = [
        "这是一个正常查询",
        "这会导致error错误",
        "另一个正常查询",
        "还有一个error错误",
        "最后一个正常查询"
    ];
    
    // 处理所有查询，优雅处理错误
    $results = [];
    $errors = [];
    
    foreach ($queries as $index => $query) {
        echo "处理查询 " . ($index + 1) . ": " . $query . "\n";
        
        try {
            $result = $processQuery($query);
            $results[] = $result;
            echo "- 结果: " . $result . "\n";
        } catch (Exception $e) {
            $errors[] = [
                'query' => $query,
                'error' => $e->getMessage()
            ];
            echo "- 错误: " . $e->getMessage() . "\n";
            echo "- 已记录错误，继续处理下一个查询\n";
        }
    }
    
    // 汇总处理结果
    echo "\n处理摘要:\n";
    echo "- 总查询数: " . count($queries) . "\n";
    echo "- 成功处理: " . count($results) . "\n";
    echo "- 处理失败: " . count($errors) . "\n";
    
    if (!empty($errors)) {
        echo "\n错误详情:\n";
        foreach ($errors as $index => $error) {
            echo ($index + 1) . ". 查询: " . $error['query'] . "\n";
            echo "   错误: " . $error['error'] . "\n";
        }
    }
}

/**
 * 示例5: 创建错误处理中间件
 */
function example5($moonshotAI) {
    echo "\n=== 示例5: 错误处理中间件 ===\n";
    
    // 定义一个简单的错误处理中间件
    $errorMiddleware = function($operation, $defaultValue = null) {
        try {
            return $operation();
        } catch (MoonshotException $e) {
            echo "MoonshotException: " . $e->getMessage() . "\n";
            return $defaultValue;
        } catch (Exception $e) {
            echo "一般异常: " . $e->getMessage() . "\n";
            return $defaultValue;
        }
    };
    
    // 使用中间件处理操作
    echo "使用中间件执行安全操作:\n";
    
    $result1 = $errorMiddleware(function() {
        return "这个操作成功了";
    }, "默认值1");
    echo "结果1: " . $result1 . "\n";
    
    $result2 = $errorMiddleware(function() {
        throw new Exception("模拟的操作失败");
    }, "默认值2");
    echo "结果2: " . $result2 . "\n";
    
    $result3 = $errorMiddleware(function() use ($moonshotAI) {
        // 一个可能成功也可能失败的操作
        try {
            return $moonshotAI->estimateTokenCount("这是一个测试句子");
        } catch (Exception $e) {
            echo "估算Token失败，但被中间件捕获\n";
            throw $e; // 重新抛出以便中间件处理
        }
    }, 0);
    echo "结果3 (Token数): " . $result3 . "\n";
}

// 运行示例
try {
    // 运行示例（注释掉实际会调用API的示例，以避免产生费用）
    example1($moonshotAI);
    //example2($moonshotAI);  // 需要真实API密钥
    example3($moonshotAI);
    example4($moonshotAI);
    example5($moonshotAI);
} catch (Exception $e) {
    echo "未捕获的异常: " . $e->getMessage() . "\n";
}

echo "\n所有示例执行完毕\n"; 