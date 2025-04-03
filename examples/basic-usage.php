<?php
/**
 * MoonshotAI SDK 基本使用示例
 * 
 * 本文件演示 MoonshotAI SDK 的基本功能和用法，包括：
 * - 基本对话
 * - 多轮对话
 * - 设置系统消息
 * - 估算 Token 数量
 * - 余额查询
 * - 流式输出
 */

// 假设您已经通过 Composer 安装了该包
// 如果是手动引入，请确保正确设置自动加载
require_once __DIR__ . '/../../../autoload.php';

use Puge2016\MoonshotAiSdk\MoonshotAI;

// 设置您的 API 密钥
$apiKey = 'your-api-key-here';

// 简单对话示例
function basicChatExample($apiKey) {
    echo "=== 基本对话示例 ===\n";
    
    // 创建 MoonshotAI 实例
    $moonshot = new MoonshotAI('simple-chat-example', 'moonshot-v1-8k', $apiKey);
    
    // 设置日志处理器
    $moonshot->setLogHandler(function($message, $level) {
        $levelNames = ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];
        $levelName = $levelNames[$level] ?? 'UNKNOWN';
        
        if ($level >= 2) { // 只显示警告及以上级别的日志
            echo "[{$levelName}] " . (is_array($message) ? json_encode($message, JSON_UNESCAPED_UNICODE) : $message) . "\n";
        }
    });
    
    try {
        // 发送消息并获取响应
        $query = "你能简单介绍一下自己吗？";
        echo "用户: " . $query . "\n";
        
        $response = $moonshot->moonshot($query);
        echo "AI: " . $response . "\n\n";
        
        // 继续对话（会保持上下文）
        $query2 = "你能给我讲个笑话吗？";
        echo "用户: " . $query2 . "\n";
        
        $response2 = $moonshot->moonshot($query2);
        echo "AI: " . $response2 . "\n";
    } catch (\Exception $e) {
        echo "错误: " . $e->getMessage() . "\n";
    }
}

// 多轮对话示例
function multiTurnConversationExample($apiKey) {
    echo "\n=== 多轮对话示例 ===\n";
    
    // 创建带有对话ID的实例以保持会话连续性
    $moonshot = new MoonshotAI('continuous-conversation-' . time(), 'moonshot-v1-8k', $apiKey);
    
    $conversation = [
        "你好，你是谁？",
        "我想了解一下机器学习的基础知识",
        "能给我推荐几本相关的书籍吗？",
        "这些书适合初学者吗？"
    ];
    
    try {
        foreach ($conversation as $index => $message) {
            echo "用户: " . $message . "\n";
            $response = $moonshot->moonshot($message);
            echo "AI: " . $response . "\n\n";
            
            // 在真实场景中可能需要等待用户输入
            if ($index < count($conversation) - 1) {
                echo "--- 按Enter继续对话 ---\n";
                fgets(STDIN);
            }
        }
    } catch (\Exception $e) {
        echo "错误: " . $e->getMessage() . "\n";
    }
}

// 设置系统消息示例
function systemMessageExample($apiKey) {
    echo "\n=== 设置系统消息示例 ===\n";
    
    $moonshot = new MoonshotAI('system-message-example', 'moonshot-v1-8k', $apiKey);
    
    try {
        // 设置自定义系统消息
        $moonshot->setSystemMessage("你是一位专业的科学顾问，专注于解释复杂的科学概念，使用通俗易懂的语言。");
        
        $query = "请解释一下黑洞是什么？";
        echo "用户: " . $query . "\n";
        
        $response = $moonshot->moonshot($query);
        echo "AI: " . $response . "\n";
    } catch (\Exception $e) {
        echo "错误: " . $e->getMessage() . "\n";
    }
}

// 估算 Token 数量示例
function tokenEstimationExample($apiKey) {
    echo "\n=== 估算 Token 数量示例 ===\n";
    
    $moonshot = new MoonshotAI('token-estimation-example', 'moonshot-v1-8k', $apiKey);
    $moonshot->setApiKey($apiKey);
    
    try {
        $text = "人工智能（AI）是计算机科学的一个分支，它强调创建能够模拟人类智能过程的智能机器。";
        echo "文本: " . $text . "\n";
        
        $tokenCount = $moonshot->estimateTokenCount($text);
        echo "估计 Token 数量: " . $tokenCount . "\n";
    } catch (\Exception $e) {
        echo "估算 Token 失败: " . $e->getMessage() . "\n";
    }
}

// 余额查询示例
function balanceCheckExample($apiKey) {
    echo "\n=== 余额查询示例 ===\n";
    
    $moonshot = new MoonshotAI('balance-check-example', 'moonshot-v1-8k', $apiKey);
    $moonshot->setApiKey($apiKey);
    
    try {
        $balance = $moonshot->getBalance();
        
        echo "账户余额详情:\n";
        echo "- 可用余额: " . ($balance['available_balance'] ?? 0) . "\n";
        echo "- 代金券余额: " . ($balance['voucher_balance'] ?? 0) . "\n";
        echo "- 现金余额: " . ($balance['cash_balance'] ?? 0) . "\n";
    } catch (\Exception $e) {
        echo "查询余额失败: " . $e->getMessage() . "\n";
    }
}

// 流式输出示例
function streamChatExample($apiKey) {
    echo "\n=== 流式输出示例 ===\n";
    
    $moonshot = new MoonshotAI('stream-chat-example', 'moonshot-v1-8k', $apiKey);
    $moonshot->setApiKey($apiKey);
    
    try {
        $query = "请写一个简短的故事，主题是友谊。";
        echo "用户: " . $query . "\n\n";
        echo "AI (流式输出): \n";
        
        // 在控制台中模拟打字效果
        $result = $moonshot->streamChat($query, function($chunk, $index, $isDone) {
            if (isset($chunk['content'])) {
                echo $chunk['content']; // 直接输出，不换行
                flush(); // 确保输出立即显示
                
                // 模拟打字延迟
                usleep(10000); // 10ms
            }
            
            if ($isDone) {
                echo "\n"; // 完成时换行
            }
        });
        
        echo "\n\n流式输出完成\n";
    } catch (\Exception $e) {
        echo "流式输出失败: " . $e->getMessage() . "\n";
    }
}

// 导出/导入会话状态示例
function sessionManagementExample($apiKey) {
    echo "\n=== 会话状态管理示例 ===\n";
    
    $moonshot = new MoonshotAI('session-management-example', 'moonshot-v1-8k', $apiKey);
    
    try {
        // 进行一些对话
        echo "用户: 你好\n";
        $response = $moonshot->moonshot("你好");
        echo "AI: " . $response . "\n\n";
        
        echo "用户: 今天天气真好\n";
        $response = $moonshot->moonshot("今天天气真好");
        echo "AI: " . $response . "\n\n";
        
        // 导出会话状态
        $sessionData = $moonshot->exportSession();
        echo "会话状态已导出，包含 " . count($sessionData['history']) . " 条历史记录\n\n";
        
        // 创建新实例并导入会话状态
        $newMoonshot = new MoonshotAI('new-session', 'moonshot-v1-8k', $apiKey);
        $newMoonshot->importSession($sessionData);
        
        echo "会话状态已导入到新实例\n";
        echo "用户: 我们刚才在聊什么？\n";
        $response = $newMoonshot->moonshot("我们刚才在聊什么？");
        echo "AI: " . $response . "\n";
    } catch (\Exception $e) {
        echo "会话管理错误: " . $e->getMessage() . "\n";
    }
}

// 可用模型列表查询示例
function listModelsExample($apiKey) {
    echo "\n=== 可用模型列表示例 ===\n";
    
    $moonshot = new MoonshotAI('list-models-example', 'moonshot-v1-8k', $apiKey);
    $moonshot->setApiKey($apiKey);
    
    try {
        $models = $moonshot->listModels();
        
        echo "可用模型列表:\n";
        if (is_array($models) && !empty($models)) {
            foreach ($models as $model) {
                echo "- ID: " . ($model['id'] ?? 'unknown') . "\n";
                echo "  名称: " . ($model['name'] ?? 'unknown') . "\n";
                if (!empty($model['description'])) {
                    echo "  描述: " . $model['description'] . "\n";
                }
                echo "\n";
            }
        } else {
            echo "未获取到模型列表或返回格式错误\n";
        }
    } catch (\Exception $e) {
        echo "获取模型列表失败: " . $e->getMessage() . "\n";
    }
}

// 执行示例
echo "MoonshotAI SDK 示例程序\n";
echo "------------------------\n\n";

// 运行基本示例
basicChatExample($apiKey);

// 取消注释以运行更多示例
// multiTurnConversationExample($apiKey);
// systemMessageExample($apiKey);
// tokenEstimationExample($apiKey);
// balanceCheckExample($apiKey);
// streamChatExample($apiKey);
// sessionManagementExample($apiKey);
// listModelsExample($apiKey);

echo "\n示例运行完毕！\n"; 