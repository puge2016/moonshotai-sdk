<?php
/**
 * MoonshotAI SDK 文件处理示例
 */

require_once __DIR__ . '/../../../autoload.php';

use Puge2016\MoonshotAiSdk\MoonshotAI;

// 设置您的 API 密钥
$apiKey = 'your-api-key-here';

// 文件上传和分析示例
function fileAnalysisExample($apiKey) {
    echo "=== 文件分析示例 ===\n";
    
    // 创建 MoonshotAI 实例
    $moonshot = new MoonshotAI('file-analysis-example', 'moonshot-v1-auto', $apiKey);
    
    // 设置日志处理器
    $moonshot->setLogHandler(function($message, $level) {
        echo "[LOG] " . json_encode($message, JSON_UNESCAPED_UNICODE) . "\n";
    });
    
    try {
        // 替换为实际的文件路径
        $files = [
            __DIR__ . '/example-files/sample.pdf',
            __DIR__ . '/example-files/document.txt'
        ];
        
        echo "上传文件中...\n";
        
        // 文件不存在时创建示例文件
        createSampleFilesIfNotExist();
        
        // 检查文件是否存在
        $existingFiles = [];
        foreach ($files as $file) {
            if (file_exists($file)) {
                $existingFiles[] = $file;
                echo "找到文件: " . basename($file) . "\n";
            } else {
                echo "文件不存在: " . basename($file) . "，已跳过\n";
            }
        }
        
        if (empty($existingFiles)) {
            echo "没有找到有效文件，示例终止\n";
            return;
        }
        
        // 上传并分析文件
        echo "开始分析文件...\n";
        $result = $moonshot->getFilesText($existingFiles);
        
        // 显示结果
        echo "\n=== 文件分析结果 ===\n";
        $resultData = json_decode($result, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "无法解析结果为JSON: " . $result . "\n";
        } else {
            echo json_encode($resultData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
    } catch (\Exception $e) {
        echo "错误: " . $e->getMessage() . "\n";
    }
}

// 创建示例文件（如果不存在）
function createSampleFilesIfNotExist() {
    $exampleDir = __DIR__ . '/example-files';
    
    // 创建示例目录
    if (!is_dir($exampleDir)) {
        mkdir($exampleDir, 0755, true);
    }
    
    // 创建文本文件
    $textFile = $exampleDir . '/document.txt';
    if (!file_exists($textFile)) {
        $content = "这是一个示例文本文档。\n\n";
        $content .= "MoonshotAI是一个强大的AI平台，提供了多种功能：\n";
        $content .= "1. 自然语言处理\n";
        $content .= "2. 文本分析与摘要\n";
        $content .= "3. 内容生成\n\n";
        $content .= "本示例用于演示SDK的文件处理功能。";
        
        file_put_contents($textFile, $content);
        echo "已创建示例文本文件: document.txt\n";
    }
    
    // 注意：无法以编程方式创建有效的PDF文件，这里只是提示
    $pdfFile = $exampleDir . '/sample.pdf';
    if (!file_exists($pdfFile)) {
        echo "请注意：需要手动添加一个有效的PDF文件到 {$exampleDir}/sample.pdf\n";
    }
}

// 执行示例
echo "MoonshotAI SDK 文件处理示例\n";
echo "----------------------------\n\n";

fileAnalysisExample($apiKey);

echo "\n示例运行完毕！\n"; 