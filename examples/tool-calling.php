<?php
/**
 * MoonshotAI SDK 工具调用功能示例
 */

require_once __DIR__ . '/../../../autoload.php';

use Puge2016\MoonshotAiSdk\MoonshotAI;

// 设置您的 API 密钥
$apiKey = 'your-api-key-here';

/**
 * 工具调用基本示例
 */
function toolCallingExample($apiKey) {
    echo "=== 工具调用基本示例 ===\n";
    
    // 创建 MoonshotAI 实例
    $moonshot = new MoonshotAI('tool-calling-example', 'moonshot-v1-8k', $apiKey);
    
    // 设置日志处理器
    $moonshot->setLogHandler(function($message, $level) {
        if ($level >= 2) {
            echo "[LOG] " . (is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE)) . "\n";
        }
    });
    
    try {
        // 定义可用工具
        $tools = [
            [
                "type" => "function",
                "function" => [
                    "name" => "CodeRunner",
                    "description" => "代码执行器，支持运行 python 和 javascript 代码",
                    "parameters" => [
                        "properties" => [
                            "language" => [
                                "type" => "string",
                                "enum" => ["python", "javascript"]
                            ],
                            "code" => [
                                "type" => "string",
                                "description" => "代码写在这里"
                            ]
                        ],
                        "type" => "object"
                    ]
                ]
            ]
        ];
        
        // 用户查询
        $query = "编程判断 3214567 是否是素数。";
        echo "用户: " . $query . "\n";
        
        // 发送请求获取工具调用响应
        $response = $moonshot->toolCall($query, $tools);
        
        // 检查是否包含工具调用
        if (isset($response['tool_calls'])) {
            echo "AI请求调用工具:\n";
            foreach ($response['tool_calls'] as $toolCall) {
                $function = $toolCall['function'];
                $name = $function['name'];
                $arguments = json_decode($function['arguments'], true);
                
                echo "- 工具名称: {$name}\n";
                echo "- 语言: {$arguments['language']}\n";
                echo "- 代码:\n{$arguments['code']}\n";
                
                // 执行代码（此处仅演示，实际执行需要适当的安全措施）
                echo "模拟执行代码...\n";
                $result = "代码执行结果: 3214567 是素数";
                
                // 回传工具执行结果
                $moonshot->history[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'name' => $name,
                    'content' => $result
                ];
            }
            
            // 获取最终响应
            $finalResponse = $moonshot->toolCall('', []);
            echo "\nAI最终回答: " . ($finalResponse['content'] ?? '') . "\n";
        } else {
            echo "AI: " . ($response['content'] ?? '') . "\n";
        }
        
    } catch (\Exception $e) {
        echo "错误: " . $e->getMessage() . "\n";
    }
}

/**
 * 完整工具调用流程示例
 */
function completeToolCallingExample($apiKey) {
    echo "\n=== 完整工具调用流程示例 ===\n";
    
    // 创建 MoonshotAI 实例
    $moonshot = new MoonshotAI('complete-tool-example', 'moonshot-v1-8k', $apiKey);
    
    // 设置日志处理器
    $moonshot->setLogHandler(function($message, $level) {
        if ($level >= 3) {
            echo "[DEBUG] " . (is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE)) . "\n";
        }
    });
    
    try {
        // 定义可用工具
        $tools = [
            [
                "type" => "function",
                "function" => [
                    "name" => "getWeather",
                    "description" => "获取指定城市的天气信息",
                    "parameters" => [
                        "properties" => [
                            "location" => [
                                "type" => "string",
                                "description" => "城市名称"
                            ],
                            "unit" => [
                                "type" => "string",
                                "enum" => ["celsius", "fahrenheit"],
                                "description" => "温度单位"
                            ]
                        ],
                        "required" => ["location"],
                        "type" => "object"
                    ]
                ]
            ],
            [
                "type" => "function",
                "function" => [
                    "name" => "searchProducts",
                    "description" => "搜索产品数据库",
                    "parameters" => [
                        "properties" => [
                            "query" => [
                                "type" => "string",
                                "description" => "搜索关键词"
                            ],
                            "category" => [
                                "type" => "string",
                                "description" => "产品类别"
                            ],
                            "max_results" => [
                                "type" => "integer",
                                "description" => "最大结果数量"
                            ]
                        ],
                        "required" => ["query"],
                        "type" => "object"
                    ]
                ]
            ]
        ];
        
        // 工具执行器
        $toolExecutor = function($name, $arguments) {
            echo "执行工具: {$name}\n";
            echo "参数: " . json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            
            // 根据工具名称执行不同的操作
            switch ($name) {
                case 'getWeather':
                    $location = $arguments['location'] ?? '北京';
                    $unit = $arguments['unit'] ?? 'celsius';
                    
                    // 模拟天气数据
                    return [
                        'location' => $location,
                        'temperature' => $unit === 'celsius' ? 23 : 73.4,
                        'unit' => $unit,
                        'condition' => '晴天',
                        'humidity' => '45%',
                        'wind' => '东北风 3级',
                    ];
                    
                case 'searchProducts':
                    $query = $arguments['query'] ?? '';
                    $category = $arguments['category'] ?? '全部';
                    $maxResults = $arguments['max_results'] ?? 3;
                    
                    // 模拟产品搜索结果
                    $products = [
                        ['name' => '商品A', 'price' => 199, 'category' => '电子'],
                        ['name' => '商品B', 'price' => 299, 'category' => '家居'],
                        ['name' => '商品C', 'price' => 99, 'category' => '服装'],
                    ];
                    
                    return [
                        'query' => $query,
                        'category' => $category,
                        'total_results' => count($products),
                        'products' => array_slice($products, 0, $maxResults)
                    ];
                    
                default:
                    return "未知工具: {$name}";
            }
        };
        
        // 用户查询
        $query = "北京今天天气怎么样？另外，我想找一些电子产品。";
        echo "用户: " . $query . "\n\n";
        
        // 执行完整的工具调用流程
        $finalResponse = $moonshot->executeToolCall($query, $tools, $toolExecutor);
        
        // 输出最终响应
        echo "\nAI: " . ($finalResponse['content'] ?? '') . "\n";
        
    } catch (\Exception $e) {
        echo "错误: " . $e->getMessage() . "\n";
    }
}

// 执行示例
echo "MoonshotAI SDK 工具调用示例程序\n";
echo "-----------------------------\n\n";

toolCallingExample($apiKey);
// completeToolCallingExample($apiKey); // 取消注释以运行完整示例

echo "\n示例运行完毕！\n"; 