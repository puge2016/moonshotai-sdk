<?php
/**
 * MoonshotAI SDK 文件处理示例
 * 
 * 本示例演示如何使用 MoonshotAI SDK 处理和分析文件，包括：
 * - 上传和分析文件
 * - 列出已上传的文件
 * - 获取文件详情
 * - 获取文件内容
 * - 删除文件
 * - 文件问答功能
 */

require_once __DIR__ . '/../../../autoload.php';

use Puge2016\MoonshotAiSdk\MoonshotAI;

// 设置您的 API 密钥
$apiKey = 'your-api-key-here';

// 文件上传和分析示例
function fileUploadExample($apiKey) {
    echo "=== 文件上传示例 ===\n";
    
    // 创建 MoonshotAI 实例
    $moonshot = new MoonshotAI('file-upload-example', 'moonshot-v1-8k', $apiKey);
    
    // 设置日志处理器
    $moonshot->setLogHandler(function($message, $level) {
        $levelNames = ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];
        $levelName = $levelNames[$level] ?? 'UNKNOWN';
        
        if ($level >= 2) { // 只显示警告及以上级别的日志
            echo "[{$levelName}] " . (is_array($message) ? json_encode($message, JSON_UNESCAPED_UNICODE) : $message) . "\n";
        }
    });
    
    try {
        // 创建示例文件
        createSampleFilesIfNotExist();
        
        // 准备上传的文件
        $exampleDir = __DIR__ . '/example-files';
        $textFile = $exampleDir . '/document.txt';
        
        if (!file_exists($textFile)) {
            echo "示例文件不存在，无法继续\n";
            return;
        }
        
        echo "上传文件: " . basename($textFile) . "\n";
        
        // 上传文件，目的是为了文本提取
        $uploadResult = $moonshot->uploadFile($textFile, 'file-extract');
        
        // 显示上传结果
        echo "文件上传成功!\n";
        echo "- 文件ID: " . ($uploadResult['id'] ?? 'unknown') . "\n";
        echo "- 文件名: " . ($uploadResult['filename'] ?? 'unknown') . "\n";
        echo "- 文件大小: " . ($uploadResult['bytes'] ?? 0) . " 字节\n";
        echo "- 用途: " . ($uploadResult['purpose'] ?? 'unknown') . "\n";
        echo "- 创建时间: " . ($uploadResult['created_at'] ?? 'unknown') . "\n";
        
        // 保存文件ID，供后续示例使用
        $fileId = $uploadResult['id'];
        echo "\n文件ID已保存: " . $fileId . "，可用于其他示例\n";
        
        return $fileId;
    } catch (\Exception $e) {
        echo "文件上传失败: " . $e->getMessage() . "\n";
        return null;
    }
}

// 列出已上传文件示例
function listFilesExample($apiKey) {
    echo "\n=== 列出已上传文件示例 ===\n";
    
    $moonshot = new MoonshotAI('list-files-example', 'moonshot-v1-8k', $apiKey);
    
    try {
        $files = $moonshot->listFiles();
        
        if (empty($files['data'] ?? [])) {
            echo "未找到已上传的文件\n";
            return;
        }
        
        echo "已上传的文件列表:\n";
        foreach ($files['data'] as $index => $file) {
            echo ($index + 1) . ". ID: " . $file['id'] . "\n";
            echo "   文件名: " . $file['filename'] . "\n";
            echo "   大小: " . $file['bytes'] . " 字节\n";
            echo "   用途: " . $file['purpose'] . "\n";
            echo "   创建时间: " . $file['created_at'] . "\n\n";
        }
        
        // 返回第一个文件的ID，供后续示例使用
        return $files['data'][0]['id'] ?? null;
    } catch (\Exception $e) {
        echo "列出文件失败: " . $e->getMessage() . "\n";
        return null;
    }
}

// 获取文件详情示例
function retrieveFileExample($apiKey, $fileId) {
    echo "\n=== 获取文件详情示例 ===\n";
    
    if (empty($fileId)) {
        echo "未提供文件ID，无法继续\n";
        return;
    }
    
    $moonshot = new MoonshotAI('retrieve-file-example', 'moonshot-v1-8k', $apiKey);
    
    try {
        echo "获取文件ID为 $fileId 的详情...\n";
        
        $fileInfo = $moonshot->retrieveFile($fileId);
        
        echo "文件详情:\n";
        echo "- 文件ID: " . ($fileInfo['id'] ?? 'unknown') . "\n";
        echo "- 文件名: " . ($fileInfo['filename'] ?? 'unknown') . "\n";
        echo "- 文件大小: " . ($fileInfo['bytes'] ?? 0) . " 字节\n";
        echo "- 用途: " . ($fileInfo['purpose'] ?? 'unknown') . "\n";
        echo "- 创建时间: " . ($fileInfo['created_at'] ?? 'unknown') . "\n";
    } catch (\Exception $e) {
        echo "获取文件详情失败: " . $e->getMessage() . "\n";
    }
}

// 获取文件内容示例
function retrieveFileContentExample($apiKey, $fileId) {
    echo "\n=== 获取文件内容示例 ===\n";
    
    if (empty($fileId)) {
        echo "未提供文件ID，无法继续\n";
        return;
    }
    
    $moonshot = new MoonshotAI('retrieve-file-content-example', 'moonshot-v1-8k', $apiKey);
    
    try {
        echo "获取文件ID为 $fileId 的内容...\n";
        
        $content = $moonshot->retrieveFileContent($fileId);
        
        echo "文件内容:\n";
        echo "--------------------------\n";
        echo $content . "\n";
        echo "--------------------------\n";
    } catch (\Exception $e) {
        echo "获取文件内容失败: " . $e->getMessage() . "\n";
    }
}

// 文件分析示例
function fileAnalysisExample($apiKey) {
    echo "\n=== 文件分析示例 ===\n";
    
    // 创建 MoonshotAI 实例
    $moonshot = new MoonshotAI('file-analysis-example', 'moonshot-v1-8k', $apiKey);
    
    try {
        // 创建示例文件
        createSampleFilesIfNotExist();
        
        // 准备上传的文件
        $exampleDir = __DIR__ . '/example-files';
        $textFile = $exampleDir . '/document.txt';
        
        if (!file_exists($textFile)) {
            echo "示例文件不存在，无法继续\n";
            return;
        }
        
        echo "准备分析文件: " . basename($textFile) . "\n";
        
        // 使用fileQA方法分析文件内容并提问
        $question = "这个文档主要内容是什么？";
        echo "问题: " . $question . "\n";
        
        $answer = $moonshot->fileQA([$textFile], $question);
        
        echo "AI回答:\n";
        echo "--------------------------\n";
        echo $answer . "\n";
        echo "--------------------------\n";
    } catch (\Exception $e) {
        echo "文件分析失败: " . $e->getMessage() . "\n";
    }
}

// 删除文件示例
function deleteFileExample($apiKey, $fileId) {
    echo "\n=== 删除文件示例 ===\n";
    
    if (empty($fileId)) {
        echo "未提供文件ID，无法继续\n";
        return;
    }
    
    $moonshot = new MoonshotAI('delete-file-example', 'moonshot-v1-8k', $apiKey);
    
    try {
        echo "删除文件ID为 $fileId 的文件...\n";
        
        $result = $moonshot->deleteFile($fileId);
        
        if ($result) {
            echo "文件删除成功!\n";
        } else {
            echo "文件删除失败\n";
        }
    } catch (\Exception $e) {
        echo "删除文件失败: " . $e->getMessage() . "\n";
    }
}

// 创建示例文件（如果不存在）
function createSampleFilesIfNotExist() {
    $exampleDir = __DIR__ . '/example-files';
    
    // 创建示例目录
    if (!is_dir($exampleDir)) {
        mkdir($exampleDir, 0755, true);
        echo "已创建示例文件目录\n";
    }
    
    // 创建文本文件
    $textFile = $exampleDir . '/document.txt';
    if (!file_exists($textFile)) {
        $content = "这是一个示例文本文档。\n\n";
        $content .= "MoonshotAI是一个强大的AI平台，提供了多种功能：\n";
        $content .= "1. 自然语言处理\n";
        $content .= "2. 文本分析与摘要\n";
        $content .= "3. 内容生成\n";
        $content .= "4. 文件处理与分析\n";
        $content .= "5. 多模态理解\n\n";
        $content .= "本示例用于演示SDK的文件处理功能。";
        
        file_put_contents($textFile, $content);
        echo "已创建示例文本文件: document.txt\n";
    }
    
    // 创建简单的HTML文件
    $htmlFile = $exampleDir . '/sample.html';
    if (!file_exists($htmlFile)) {
        $content = "<!DOCTYPE html>\n";
        $content .= "<html>\n";
        $content .= "<head>\n";
        $content .= "    <title>MoonshotAI 示例</title>\n";
        $content .= "</head>\n";
        $content .= "<body>\n";
        $content .= "    <h1>MoonshotAI SDK 文件处理示例</h1>\n";
        $content .= "    <p>这是一个示例HTML文件，用于测试SDK的文件处理功能。</p>\n";
        $content .= "    <ul>\n";
        $content .= "        <li>支持多种文件格式</li>\n";
        $content .= "        <li>高效分析文件内容</li>\n";
        $content .= "        <li>对文件内容进行问答</li>\n";
        $content .= "    </ul>\n";
        $content .= "</body>\n";
        $content .= "</html>";
        
        file_put_contents($htmlFile, $content);
        echo "已创建示例HTML文件: sample.html\n";
    }
    
    // 注意：无法以编程方式创建有效的PDF文件，这里只是提示
    $pdfFile = $exampleDir . '/sample.pdf';
    if (!file_exists($pdfFile)) {
        echo "请注意：需要手动添加一个有效的PDF文件到 {$exampleDir}/sample.pdf\n";
    }
}

// 多文件问答示例
function multiFileQAExample($apiKey) {
    echo "\n=== 多文件问答示例 ===\n";
    
    // 创建 MoonshotAI 实例
    $moonshot = new MoonshotAI('multi-file-qa-example', 'moonshot-v1-8k', $apiKey);
    
    try {
        // 创建示例文件
        createSampleFilesIfNotExist();
        
        // 准备上传的文件
        $exampleDir = __DIR__ . '/example-files';
        $textFile = $exampleDir . '/document.txt';
        $htmlFile = $exampleDir . '/sample.html';
        
        $files = [];
        if (file_exists($textFile)) {
            $files[] = $textFile;
            echo "使用文本文件: " . basename($textFile) . "\n";
        }
        
        if (file_exists($htmlFile)) {
            $files[] = $htmlFile;
            echo "使用HTML文件: " . basename($htmlFile) . "\n";
        }
        
        if (empty($files)) {
            echo "未找到可用的示例文件，无法继续\n";
            return;
        }
        
        // 提问并获取回答
        $question = "对比这些文件内容，它们有什么共同点？";
        echo "问题: " . $question . "\n";
        
        // 启用缓存，提高后续查询性能
        $answer = $moonshot->fileQA($files, $question, [], true, 3600);
        
        echo "AI回答:\n";
        echo "--------------------------\n";
        echo $answer . "\n";
        echo "--------------------------\n";
    } catch (\Exception $e) {
        echo "多文件问答失败: " . $e->getMessage() . "\n";
    }
}

// 执行示例
echo "MoonshotAI SDK 文件处理示例\n";
echo "----------------------------\n\n";

// 尝试运行示例（注释掉实际会调用API的示例，以避免产生费用）
// 先创建示例文件
createSampleFilesIfNotExist();

// 文件上传示例（取消注释以运行）
// $fileId = fileUploadExample($apiKey);

// 如果有文件ID，可以运行其他依赖文件ID的示例
// if (!empty($fileId)) {
//     retrieveFileExample($apiKey, $fileId);
//     retrieveFileContentExample($apiKey, $fileId);
//     deleteFileExample($apiKey, $fileId);
// } else {
//     // 如果没有文件ID，可以尝试列出已有文件
//     $existingFileId = listFilesExample($apiKey);
//     if (!empty($existingFileId)) {
//         retrieveFileExample($apiKey, $existingFileId);
//         retrieveFileContentExample($apiKey, $existingFileId);
//     }
// }

// 文件分析示例（取消注释以运行）
// fileAnalysisExample($apiKey);

// 多文件问答示例（取消注释以运行）
// multiFileQAExample($apiKey);

echo "\n示例运行完毕！\n"; 