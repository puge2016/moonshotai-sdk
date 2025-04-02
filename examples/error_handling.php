<?php
/**
 * MoonshotAI SDK 错误处理示例
 * 
 * 本示例展示了如何使用MoonshotAI SDK的错误处理功能
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Puge2016\MoonshotAiSdk\MoonshotAI;
use Puge2016\MoonshotAiSdk\Exceptions\MoonshotException;

// 你的API密钥
$apiKey = 'your_api_key_here';

// 创建MoonshotAI实例
$moonshotAI = new MoonshotAI();
$moonshotAI->setApiKey($apiKey);

// 设置自定义的日志处理器
$moonshotAI->setLogHandler(function($message, $level) {
    $levelNames = ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];
    $levelName = $levelNames[$level] ?? 'UNKNOWN';
    
    // 将日志消息格式化为字符串
    if (is_array($message)) {
        $message = implode(' | ', $message);
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] [$levelName] $message\n";
});

// 设置重试配置 - 最多重试3次，初始延迟为1秒，最大延迟为10秒
$moonshotAI->setRetryConfig(3, 1, 10);

/**
 * 示例1: 捕获和处理常见API错误
 */
function example1($moonshotAI) {
    echo "=== 示例1: 捕获和处理常见API错误 ===\n";
    
    try {
        // 故意使用过长的提示文本来触发上下文长度错误
        $longPrompt = str_repeat("这是一个非常长的文本，用于测试上下文长度限制。", 2000);
        $result = $moonshotAI->moonshot($longPrompt);
        
        echo "成功获取回复: " . substr($result, 0, 100) . "...\n";
    } catch (MoonshotException $e) {
        echo "\n捕获到MoonshotException:\n";
        echo $moonshotAI->getFormattedError($e, true) . "\n";
        
        // 根据错误类型执行不同的处理
        if ($e->isContextLengthError()) {
            echo "处理上下文长度错误: 尝试截断输入文本...\n";
            // 实际应用中可以在这里处理截断逻辑
        } elseif ($e->isContentFilterError()) {
            echo "处理内容过滤错误: 输入内容可能包含敏感信息\n";
        } elseif ($e->isRateLimitError()) {
            echo "处理速率限制错误: 等待一段时间后重试\n";
        }
    } catch (Exception $e) {
        echo "\n捕获到一般Exception:\n";
        echo $moonshotAI->getFormattedError($e) . "\n";
    }
}

/**
 * 示例2: 使用safeExecute包装函数执行，避免中断程序
 */
function example2($moonshotAI) {
    echo "\n=== 示例2: 使用safeExecute包装函数执行 ===\n";
    
    // 定义一个可能会抛出异常的函数
    $operation = function() use ($moonshotAI) {
        // 故意使用无效的模型名称
        $moonshotAI->setModelType('non-existent-model');
        return $moonshotAI->moonshot("你好，这是一个测试");
    };
    
    // 使用反射来调用受保护的方法
    $reflectionMethod = new ReflectionMethod(MoonshotAI::class, 'safeExecute');
    $reflectionMethod->setAccessible(true);
    
    $result = $reflectionMethod->invoke(
        $moonshotAI, 
        $operation,
        "操作失败，返回默认值",
        "API调用失败",
        false,
        2
    );
    
    echo "操作结果: " . ($result ?: "操作失败，使用了默认值") . "\n";
}

/**
 * 示例3: 演示错误重试机制
 */
function example3($moonshotAI) {
    echo "\n=== 示例3: 演示错误重试机制 ===\n";
    
    // 临时将API密钥设置为无效值，触发401错误
    $originalApiKey = $moonshotAI->getApiKey();
    $moonshotAI->setApiKey('invalid_api_key');
    
    try {
        $result = $moonshotAI->moonshot("这是一个测试消息");
        echo "成功获取回复(不应该到达这里): " . substr($result, 0, 100) . "...\n";
    } catch (MoonshotException $e) {
        echo "\n认证失败错误(预期行为):\n";
        echo $moonshotAI->getFormattedError($e) . "\n";
        
        if ($e->isAuthError()) {
            echo "检测到认证错误，恢复有效的API密钥...\n";
            $moonshotAI->setApiKey($originalApiKey);
        }
    }
    
    // 恢复原始API密钥
    $moonshotAI->setApiKey($originalApiKey);
}

/**
 * 示例4: 错误恢复机制
 */
function example4($moonshotAI) {
    echo "\n=== 示例4: 错误恢复机制 ===\n";
    
    // 定义一个可能会根据条件失败的函数
    $operation = function() {
        // 模拟随机故障
        if (rand(0, 1) == 0) {
            throw new Exception("模拟的随机故障");
        }
        return "操作成功完成";
    };
    
    // 模拟多次尝试，直到成功
    $maxAttempts = 5;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        echo "尝试 $attempt/$maxAttempts...\n";
        
        // 使用反射来调用受保护的方法
        $reflectionMethod = new ReflectionMethod(MoonshotAI::class, 'safeExecute');
        $reflectionMethod->setAccessible(true);
        
        $result = $reflectionMethod->invoke(
            $moonshotAI, 
            $operation,
            null,
            "操作失败，尝试重试",
            false,
            1
        );
        
        if ($result !== null) {
            echo "成功: $result\n";
            break;
        }
        
        if ($attempt < $maxAttempts) {
            $delay = pow(2, $attempt - 1);
            echo "等待 {$delay} 秒后重试...\n";
            // 实际代码中可以使用 sleep($delay)
        } else {
            echo "达到最大尝试次数，操作最终失败\n";
        }
    }
}

// 运行示例
try {
    // 注释掉实际执行可能触发API调用的示例
    // example1($moonshotAI);
    example2($moonshotAI);
    example3($moonshotAI);
    example4($moonshotAI);
} catch (Exception $e) {
    echo "未捕获的异常: " . $e->getMessage() . "\n";
}

echo "\n所有示例执行完毕\n"; 