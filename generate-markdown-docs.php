<?php

/**
 * 生成 Markdown 格式的 API 文档
 *
 * 使用方法：php generate-markdown-docs.php
 * 输出文件：docs/API.md
 */

require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

// 配置
$scribeDir = __DIR__.'/.scribe';
$endpointsDir = $scribeDir.'/endpoints';
$outputFile = __DIR__.'/docs/API.md';

// 确保输出目录存在
if (! is_dir(dirname($outputFile))) {
    mkdir(dirname($outputFile), 0755, true);
}

// 读取介绍和认证信息
$intro = file_exists($scribeDir.'/intro.md') ? file_get_contents($scribeDir.'/intro.md') : '';
$auth = file_exists($scribeDir.'/auth.md') ? file_get_contents($scribeDir.'/auth.md') : '';

// 开始生成 Markdown
$markdown = "# DVideo API 文档\n\n";
$markdown .= '> 自动生成时间：'.date('Y-m-d H:i:s')."\n\n";

// 添加介绍
if ($intro) {
    $markdown .= $intro."\n\n";
}

// 添加认证信息
if ($auth) {
    $markdown .= "## 认证方式\n\n";
    $markdown .= $auth."\n\n";
}

// 添加目录
$markdown .= "## 目录\n\n";

// 读取所有端点文件
$endpointFiles = glob($endpointsDir.'/*.yaml');
sort($endpointFiles);

$groups = [];

// 第一遍：收集所有分组和端点
foreach ($endpointFiles as $file) {
    $data = Yaml::parseFile($file);

    if (! isset($data['name'])) {
        continue;
    }

    $groupName = $data['name'];
    $groups[$groupName] = $data['endpoints'] ?? [];
}

// 生成目录
foreach ($groups as $groupName => $endpoints) {
    $anchor = strtolower(str_replace([' ', '-'], '_', $groupName));
    $markdown .= "- [{$groupName}](#{$anchor})\n";

    foreach ($endpoints as $endpoint) {
        $method = $endpoint['httpMethods'][0] ?? 'GET';
        $uri = $endpoint['uri'] ?? '';
        $title = $endpoint['metadata']['title'] ?? $uri;
        $endpointAnchor = strtolower(str_replace(['/', '{', '}', ' ', '-'], '_', $method.'_'.$uri));
        $markdown .= "  - [{$method} {$uri}](#{$endpointAnchor})\n";
    }
}

$markdown .= "\n---\n\n";

// 第二遍：生成详细文档
foreach ($groups as $groupName => $endpoints) {
    $anchor = strtolower(str_replace([' ', '-'], '_', $groupName));
    $markdown .= "## {$groupName} {#".$anchor."}\n\n";

    foreach ($endpoints as $endpoint) {
        $method = $endpoint['httpMethods'][0] ?? 'GET';
        $uri = $endpoint['uri'] ?? '';
        $title = $endpoint['metadata']['title'] ?? $uri;
        $description = $endpoint['metadata']['description'] ?? '';
        $authenticated = $endpoint['metadata']['authenticated'] ?? false;

        $endpointAnchor = strtolower(str_replace(['/', '{', '}', ' ', '-'], '_', $method.'_'.$uri));

        $markdown .= "### {$title} {#".$endpointAnchor."}\n\n";
        $markdown .= "**请求方式：** `{$method}`\n\n";
        $markdown .= "**请求路径：** `{$uri}`\n\n";

        if ($authenticated) {
            $markdown .= "**需要认证：** ✅ 是\n\n";
        } else {
            $markdown .= "**需要认证：** ❌ 否\n\n";
        }

        if ($description) {
            $markdown .= "**接口描述：**\n\n{$description}\n\n";
        }

        // URL 参数
        if (! empty($endpoint['urlParameters'])) {
            $markdown .= "**URL 参数：**\n\n";
            $markdown .= "| 参数名 | 类型 | 必填 | 描述 | 示例 |\n";
            $markdown .= "|--------|------|------|------|------|\n";

            foreach ($endpoint['urlParameters'] as $param) {
                $name = $param['name'] ?? '';
                $type = $param['type'] ?? 'string';
                $required = ($param['required'] ?? false) ? '✅' : '❌';
                $desc = $param['description'] ?? '';
                $example = $param['example'] ?? '';

                // 处理数组类型的示例值
                if (is_array($example)) {
                    $example = json_encode($example, JSON_UNESCAPED_UNICODE);
                }

                $markdown .= "| `{$name}` | {$type} | {$required} | {$desc} | `{$example}` |\n";
            }

            $markdown .= "\n";
        }

        // Query 参数
        if (! empty($endpoint['queryParameters'])) {
            $markdown .= "**Query 参数：**\n\n";
            $markdown .= "| 参数名 | 类型 | 必填 | 描述 | 示例 |\n";
            $markdown .= "|--------|------|------|------|------|\n";

            foreach ($endpoint['queryParameters'] as $param) {
                $name = $param['name'] ?? '';
                $type = $param['type'] ?? 'string';
                $required = ($param['required'] ?? false) ? '✅' : '❌';
                $desc = $param['description'] ?? '';
                $example = $param['example'] ?? '';

                // 处理数组类型的示例值
                if (is_array($example)) {
                    $example = json_encode($example, JSON_UNESCAPED_UNICODE);
                }

                $markdown .= "| `{$name}` | {$type} | {$required} | {$desc} | `{$example}` |\n";
            }

            $markdown .= "\n";
        }

        // Body 参数
        if (! empty($endpoint['bodyParameters'])) {
            $markdown .= "**Body 参数：**\n\n";
            $markdown .= "| 参数名 | 类型 | 必填 | 描述 | 示例 |\n";
            $markdown .= "|--------|------|------|------|------|\n";

            foreach ($endpoint['bodyParameters'] as $param) {
                $name = $param['name'] ?? '';
                $type = $param['type'] ?? 'string';
                $required = ($param['required'] ?? false) ? '✅' : '❌';
                $desc = $param['description'] ?? '';
                $example = $param['example'] ?? '';

                // 处理数组类型的示例值
                if (is_array($example)) {
                    $example = json_encode($example, JSON_UNESCAPED_UNICODE);
                }

                $markdown .= "| `{$name}` | {$type} | {$required} | {$desc} | `{$example}` |\n";
            }

            $markdown .= "\n";
        }

        // 响应示例
        if (! empty($endpoint['responses'])) {
            $markdown .= "**响应示例：**\n\n";

            foreach ($endpoint['responses'] as $response) {
                $status = $response['status'] ?? 200;
                $description = $response['description'] ?? '';
                $content = $response['content'] ?? '';

                if ($description) {
                    $markdown .= "**{$description}** (HTTP {$status})：\n\n";
                } else {
                    $markdown .= "**HTTP {$status}**：\n\n";
                }

                if ($content) {
                    // 尝试格式化 JSON
                    $jsonData = json_decode($content, true);
                    if ($jsonData !== null) {
                        $content = json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    }

                    $markdown .= "```json\n{$content}\n```\n\n";
                }
            }
        }

        // 示例代码
        $markdown .= "**请求示例：**\n\n";

        // Bash/cURL 示例
        $curlCmd = "curl -X {$method}";

        if ($authenticated) {
            $curlCmd .= " \\\n  -H \"Authorization: Bearer YOUR_TOKEN\"";
        }

        $curlCmd .= " \\\n  -H \"Accept: application/json\"";

        if (! empty($endpoint['bodyParameters'])) {
            $curlCmd .= " \\\n  -H \"Content-Type: application/json\"";

            $bodyExample = [];
            foreach ($endpoint['bodyParameters'] as $param) {
                $bodyExample[$param['name']] = $param['example'] ?? '';
            }

            $curlCmd .= " \\\n  -d '".json_encode($bodyExample, JSON_UNESCAPED_UNICODE)."'";
        }

        $fullUri = $uri;
        if (! empty($endpoint['urlParameters'])) {
            foreach ($endpoint['urlParameters'] as $param) {
                $fullUri = str_replace('{'.$param['name'].'}', $param['example'] ?? '1', $fullUri);
            }
        }

        $curlCmd .= " \\\n  \"http://localhost:8000/{$fullUri}\"";

        if (! empty($endpoint['queryParameters'])) {
            $queryParams = [];
            foreach ($endpoint['queryParameters'] as $param) {
                if (! empty($param['example'])) {
                    $queryParams[] = $param['name'].'='.urlencode($param['example']);
                }
            }
            if (! empty($queryParams)) {
                $curlCmd .= '?'.implode('&', $queryParams);
            }
        }

        $markdown .= "```bash\n{$curlCmd}\n```\n\n";

        // JavaScript 示例
        $jsCode = "fetch('http://localhost:8000/{$fullUri}";

        if (! empty($endpoint['queryParameters'])) {
            $queryParams = [];
            foreach ($endpoint['queryParameters'] as $param) {
                if (! empty($param['example'])) {
                    $queryParams[] = $param['name'].'='.urlencode($param['example']);
                }
            }
            if (! empty($queryParams)) {
                $jsCode .= '?'.implode('&', $queryParams);
            }
        }

        $jsCode .= "', {\n  method: '{$method}',\n  headers: {\n";

        if ($authenticated) {
            $jsCode .= "    'Authorization': 'Bearer YOUR_TOKEN',\n";
        }

        $jsCode .= "    'Accept': 'application/json'";

        if (! empty($endpoint['bodyParameters'])) {
            $jsCode .= ",\n    'Content-Type': 'application/json'";
            $bodyExample = [];
            foreach ($endpoint['bodyParameters'] as $param) {
                $bodyExample[$param['name']] = $param['example'] ?? '';
            }
            $jsCode .= "\n  },\n  body: JSON.stringify(".json_encode($bodyExample, JSON_UNESCAPED_UNICODE).')';
        } else {
            $jsCode .= "\n  }";
        }

        $jsCode .= "\n})\n.then(response => response.json())\n.then(data => console.log(data));";

        $markdown .= "```javascript\n{$jsCode}\n```\n\n";

        $markdown .= "---\n\n";
    }
}

// 写入文件
file_put_contents($outputFile, $markdown);

echo "✅ Markdown 文档已生成：{$outputFile}\n";
echo '📄 文件大小：'.number_format(filesize($outputFile) / 1024, 2)." KB\n";
echo '📊 包含分组：'.count($groups)." 个\n";

$totalEndpoints = 0;
foreach ($groups as $endpoints) {
    $totalEndpoints += count($endpoints);
}
echo "📌 包含端点：{$totalEndpoints} 个\n";
