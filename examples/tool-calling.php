<?php
/**
 * MoonshotAI SDK 工具调用功能示例
 * 
 * 本示例演示如何使用 MoonshotAI SDK 的工具调用功能：
 * - 基本工具调用
 * - 工具函数执行
 * - 多工具调用场景
 * - 处理工具调用结果
 * - 构建实用工具集
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
        $levelNames = ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];
        $levelName = $levelNames[$level] ?? 'UNKNOWN';
        
        if ($level >= 2) { // 只显示警告及以上级别的日志
            echo "[{$levelName}] " . (is_array($message) ? json_encode($message, JSON_UNESCAPED_UNICODE) : $message) . "\n";
        }
    });
    
    try {
        // 定义可用工具
        $tools = [
            [
                "type" => "function",
                "function" => [
                    "name" => "CodeRunner",
                    "description" => "代码执行器，支持运行 Python 和 JavaScript 代码",
                    "parameters" => [
                        "type" => "object",
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
                        "required" => ["language", "code"]
                    ]
                ]
            ]
        ];
        
        // 用户查询
        $query = "判断 3214567 是否是素数。";
        echo "用户: " . $query . "\n";
        
        // 发送请求获取工具调用响应
        $response = $moonshot->toolCall($query, $tools);
        
        // 检查是否包含工具调用
        if (isset($response['tool_calls']) && !empty($response['tool_calls'])) {
            echo "\nAI请求调用工具:\n";
            foreach ($response['tool_calls'] as $toolCall) {
                $function = $toolCall['function'];
                $name = $function['name'];
                $arguments = json_decode($function['arguments'], true);
                
                echo "- 工具名称: {$name}\n";
                echo "- 语言: {$arguments['language']}\n";
                echo "- 代码:\n{$arguments['code']}\n";
                
                // 执行代码（此处仅演示，实际执行需要适当的安全措施）
                echo "\n模拟执行代码...\n";
                
                if ($arguments['language'] === 'python') {
                    $result = "Python代码执行结果: 3214567 是素数";
                } else {
                    $result = "JavaScript代码执行结果: 3214567 是素数";
                }
                
                echo $result . "\n";
                
                // 回传工具执行结果
                echo "\n将结果回传给AI...\n";
                $moonshot->updateHistoryWithMessages([
                    [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => $toolCall['id'],
                                'type' => 'function',
                                'function' => [
                                    'name' => $name,
                                    'arguments' => $function['arguments']
                                ]
                            ]
                        ]
                    ],
                    [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'name' => $name,
                        'content' => $result
                    ]
                ]);
            }
            
            // 获取最终响应
            $finalResponse = $moonshot->moonshot("");
            echo "\nAI最终回答: " . $finalResponse . "\n";
        } else {
            echo "\nAI直接回答: " . ($response['content'] ?? '无响应') . "\n";
        }
        
    } catch (\Exception $e) {
        echo "错误: " . $e->getMessage() . "\n";
    }
}

/**
 * 完整工具调用流程示例 - 使用自动执行器
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
                        "type" => "object",
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
                        "required" => ["location"]
                    ]
                ]
            ],
            [
                "type" => "function",
                "function" => [
                    "name" => "searchProducts",
                    "description" => "搜索产品数据库",
                    "parameters" => [
                        "type" => "object",
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
                        "required" => ["query"]
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
                        'temperature' => $unit === 'celsius' ? rand(15, 30) : rand(60, 85),
                        'unit' => $unit,
                        'condition' => ['晴天', '多云', '小雨', '阴天'][rand(0, 3)],
                        'humidity' => rand(30, 80) . '%',
                        'wind' => ['东北风', '西南风', '东南风', '西北风'][rand(0, 3)] . ' ' . rand(1, 5) . '级',
                    ];
                    
                case 'searchProducts':
                    $query = $arguments['query'] ?? '';
                    $category = $arguments['category'] ?? '全部';
                    $maxResults = $arguments['max_results'] ?? 3;
                    
                    // 模拟产品搜索结果
                    $products = [
                        ['name' => $query . ' 高级版', 'price' => rand(100, 500), 'category' => '电子'],
                        ['name' => $query . ' 标准版', 'price' => rand(50, 300), 'category' => '家居'],
                        ['name' => $query . ' 入门版', 'price' => rand(20, 150), 'category' => '服装'],
                        ['name' => $query . ' 专业版', 'price' => rand(200, 800), 'category' => '电子'],
                        ['name' => $query . ' 豪华版', 'price' => rand(500, 1500), 'category' => '奢侈品'],
                    ];
                    
                    // 如果指定了类别，过滤结果
                    if ($category !== '全部') {
                        $products = array_filter($products, function($product) use ($category) {
                            return $product['category'] === $category;
                        });
                    }
                    
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
        $result = $moonshot->executeToolCall($query, $tools, $toolExecutor);
        
        // 输出最终响应
        echo "\nAI: " . ($result['content'] ?? '无响应') . "\n";
        
    } catch (\Exception $e) {
        echo "错误: " . $e->getMessage() . "\n";
    }
}

/**
 * 多工具链式调用示例
 */
function chainedToolCallsExample($apiKey) {
    echo "\n=== 多工具链式调用示例 ===\n";
    
    // 创建 MoonshotAI 实例
    $moonshot = new MoonshotAI('chained-tools-example', 'moonshot-v1-8k', $apiKey);
    
    try {
        // 定义可用工具
        $tools = [
            [
                "type" => "function",
                "function" => [
                    "name" => "getCurrentDate",
                    "description" => "获取当前日期",
                    "parameters" => [
                        "type" => "object",
                        "properties" => [
                            "format" => [
                                "type" => "string",
                                "description" => "日期格式，例如 Y-m-d"
                            ]
                        ]
                    ]
                ]
            ],
            [
                "type" => "function",
                "function" => [
                    "name" => "calculateAge",
                    "description" => "计算从指定日期到现在的年龄或天数",
                    "parameters" => [
                        "type" => "object",
                        "properties" => [
                            "birthdate" => [
                                "type" => "string",
                                "description" => "出生日期，格式为 YYYY-MM-DD"
                            ],
                            "unit" => [
                                "type" => "string",
                                "enum" => ["years", "days"],
                                "description" => "返回年龄的单位：years（年）或 days（天）"
                            ]
                        ],
                        "required" => ["birthdate"]
                    ]
                ]
            ],
            [
                "type" => "function",
                "function" => [
                    "name" => "getHoroscope",
                    "description" => "根据生日获取星座和运势",
                    "parameters" => [
                        "type" => "object",
                        "properties" => [
                            "birthdate" => [
                                "type" => "string",
                                "description" => "出生日期，格式为 YYYY-MM-DD"
                            ]
                        ],
                        "required" => ["birthdate"]
                    ]
                ]
            ]
        ];
        
        // 工具执行器
        $toolExecutor = function($name, $arguments) {
            echo "执行工具: {$name}\n";
            
            switch ($name) {
                case 'getCurrentDate':
                    $format = $arguments['format'] ?? 'Y-m-d';
                    return date($format);
                    
                case 'calculateAge':
                    $birthdate = $arguments['birthdate'] ?? '';
                    $unit = $arguments['unit'] ?? 'years';
                    
                    $birth = new DateTime($birthdate);
                    $now = new DateTime();
                    $interval = $now->diff($birth);
                    
                    if ($unit === 'years') {
                        return [
                            'age' => $interval->y,
                            'unit' => '年'
                        ];
                    } else {
                        $days = $interval->days;
                        return [
                            'age' => $days,
                            'unit' => '天'
                        ];
                    }
                    
                case 'getHoroscope':
                    $birthdate = $arguments['birthdate'] ?? '';
                    
                    if (empty($birthdate)) {
                        return "需要提供出生日期";
                    }
                    
                    // 解析生日并获取星座
                    $timestamp = strtotime($birthdate);
                    $month = (int)date('n', $timestamp);
                    $day = (int)date('j', $timestamp);
                    
                    $signs = [
                        "水瓶座", "双鱼座", "白羊座", "金牛座", "双子座", "巨蟹座",
                        "狮子座", "处女座", "天秤座", "天蝎座", "射手座", "摩羯座"
                    ];
                    
                    $dates = [21, 20, 21, 21, 22, 22, 23, 24, 24, 24, 23, 22];
                    
                    $zodiac = ($day < $dates[$month - 1]) ? $month - 1 : $month;
                    $zodiac = ($zodiac == 0) ? 12 : $zodiac;
                    $zodiac = $signs[$zodiac - 1];
                    
                    // 模拟运势
                    $fortunes = [
                        "今天的运势非常好，适合开始新的项目。",
                        "今天可能会遇到一些挑战，但保持积极的态度。",
                        "今天是反思和规划的好日子，多花时间思考未来。",
                        "今天的社交运势很好，是结交新朋友的好时机。",
                        "今天财运不错，可能会有意外之财。"
                    ];
                    
                    return [
                        'zodiac' => $zodiac,
                        'fortune' => $fortunes[array_rand($fortunes)]
                    ];
                    
                default:
                    return "未知工具: {$name}";
            }
        };
        
        // 用户查询
        $query = "我出生于1990年5月15日，请告诉我我的年龄和星座运势。";
        echo "用户: " . $query . "\n\n";
        
        // 执行工具调用流程
        $result = $moonshot->executeToolCall($query, $tools, $toolExecutor);
        
        // 输出最终响应
        echo "\nAI: " . ($result['content'] ?? '无响应') . "\n";
        
    } catch (\Exception $e) {
        echo "错误: " . $e->getMessage() . "\n";
    }
}

/**
 * PHP代码执行工具示例
 */
function phpCodeExecutionExample($apiKey) {
    echo "\n=== PHP代码执行工具示例 ===\n";
    
    // 创建 MoonshotAI 实例
    $moonshot = new MoonshotAI('php-execution-example', 'moonshot-v1-8k', $apiKey);
    
    try {
        // 定义可用工具
        $tools = [
            [
                "type" => "function",
                "function" => [
                    "name" => "executePhpCode",
                    "description" => "执行PHP代码并返回结果",
                    "parameters" => [
                        "type" => "object",
                        "properties" => [
                            "code" => [
                                "type" => "string",
                                "description" => "PHP代码"
                            ]
                        ],
                        "required" => ["code"]
                    ]
                ]
            ]
        ];
        
        // 工具执行器
        $toolExecutor = function($name, $arguments) {
            if ($name !== 'executePhpCode') {
                return "不支持的工具: {$name}";
            }
            
            $code = $arguments['code'] ?? '';
            
            // 安全执行PHP代码
            // 注意：在生产环境中，执行用户代码可能存在严重安全风险，应谨慎使用
            ob_start();
            $output = null;
            $success = true;
            $error = '';
            
            try {
                // 使用临时文件执行PHP代码
                $tmpFile = tempnam(sys_get_temp_dir(), 'php_exec_');
                file_put_contents($tmpFile, "<?php\n" . $code);
                
                // 执行代码并捕获输出
                $output = include $tmpFile;
                
                // 清理临时文件
                unlink($tmpFile);
            } catch (\Throwable $e) {
                $success = false;
                $error = $e->getMessage();
            }
            
            $output_buffer = ob_get_clean();
            
            return [
                'success' => $success,
                'output' => $output,
                'output_buffer' => $output_buffer,
                'error' => $error
            ];
        };
        
        // 用户查询
        $query = "写一段PHP代码，计算斐波那契数列的前10个数字";
        echo "用户: " . $query . "\n\n";
        
        // 执行工具调用流程
        $result = $moonshot->executeToolCall($query, $tools, $toolExecutor);
        
        // 输出最终响应
        echo "\nAI: " . ($result['content'] ?? '无响应') . "\n";
        
    } catch (\Exception $e) {
        echo "错误: " . $e->getMessage() . "\n";
    }
}

// 执行示例
echo "MoonshotAI SDK 工具调用示例程序\n";
echo "-----------------------------\n\n";

// 注释掉实际会调用API的示例，以避免产生费用
// toolCallingExample($apiKey);
// completeToolCallingExample($apiKey);
// chainedToolCallsExample($apiKey);
// phpCodeExecutionExample($apiKey);

// 提示用户如何使用
echo "请取消注释相应的函数调用来运行示例。\n";
echo "示例程序准备就绪！\n"; 