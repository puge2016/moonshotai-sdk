<?php
/**
 * MoonshotAI SDK 基本使用示例
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
    $moonshot = new MoonshotAI('simple-chat-example', 'moonshot-v1-auto', $apiKey);
    
    // 设置日志处理器
    $moonshot->setLogHandler(function($message, $level) {
        if ($level >= 2) {
            echo "[LOG] " . json_encode($message, JSON_UNESCAPED_UNICODE) . "\n";
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
    $moonshot = new MoonshotAI('continuous-conversation-' . time(), 'moonshot-v1-auto', $apiKey);
    
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

// 余额查询示例
function balanceCheckExample($apiKey) {
    echo "\n=== 余额查询示例 ===\n";
    
    $moonshot = new MoonshotAI();
    $moonshot->setApiKey($apiKey);
    
    try {
        $balance = $moonshot->getBalance();
        echo "当前账户余额: " . $balance . " 元\n";
    } catch (\Exception $e) {
        echo "查询余额失败: " . $e->getMessage() . "\n";
    }
}

// 执行示例
echo "MoonshotAI SDK 示例程序\n";
echo "------------------------\n\n";

// 运行各个示例
basicChatExample($apiKey);
//multiTurnConversationExample($apiKey);  // 取消注释以运行多轮对话示例
//balanceCheckExample($apiKey);  // 取消注释以运行余额查询示例

echo "\n示例运行完毕！\n"; 