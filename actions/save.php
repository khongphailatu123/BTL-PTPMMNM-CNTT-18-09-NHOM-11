<?php
// actions/save.php - Bộ cào dữ liệu tối ưu Đa luồng + Nhận diện chuẩn Light Novel & Truyện tranh
set_time_limit(0); 
ini_set('memory_limit', '512M');

require_once '../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1;

function getAdvancedHeaders($referer, $isMobile = false) {
    $ua = $isMobile 
        ? 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36'
        : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    return [
        'User-Agent: ' . $ua,
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language: vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Sec-Ch-Ua-Mobile: ' . ($isMobile ? '?1' : '?0'),
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: same-origin',
        'Upgrade-Insecure-Requests: 1',
        'Referer: ' . $referer
    ];
}

function executeCurlRequest($url, $referer, $isMobile = false) {
    $ch = curl_init($url);
    $cookieFile = sys_get_temp_dir() . '/ck_' . md5($url) . '.txt';

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384');
    curl_setopt($ch, CURLOPT_HTTPHEADER, getAdvancedHeaders($referer, $isMobile));
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);

    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    @unlink($cookieFile);

    return ['data' => $data, 'code' => $code];
}

function fetchSingleImage($url, $referer) {
    for ($i = 0; $i < 3; $i++) {
        $res = executeCurlRequest($url, $referer, false);
        if ($res['data'] !== false && ($res['code'] === 200 || $res['code'] === 304) && strlen($res['data']) > 500) {
            return $res['data'];
        }
        $resMob = executeCurlRequest($url, $referer, true);
        if ($resMob['data'] !== false && ($resMob['code'] === 200 || $resMob['code'] === 304) && strlen($resMob['data']) > 500) {
            return $resMob['data'];
        }
        sleep(1);
    }
    return false;
}

function downloadImagesMulti($imgTasks, $articleDir, $folderName) {
    if (empty($imgTasks)) return;

    $mh = curl_multi_init();
    $curlHandles = [];

    foreach ($imgTasks as $index => $task) {
        $ch = curl_init($task['src']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, getAdvancedHeaders($task['referer'], false));
        curl_setopt($ch, CURLOPT_ENCODING, '');
        
        curl_multi_add_handle($mh, $ch);
        $curlHandles[$index] = [
            'handle' => $ch,
            'node' => $task['node'],
            'src' => $task['src'],
            'referer' => $task['referer']
        ];
    }

    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($active > 0);

    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($mh) !== -1) {
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($active > 0);
        }
        usleep(100);
    }

    $imgIndex = 1;
    foreach ($curlHandles as $index => $item) {
        $ch = $item['handle'];
        $imgData = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($imgData === false || ($httpCode !== 200 && $httpCode !== 304) || strlen($imgData) <= 500) {
            $imgData = fetchSingleImage($item['src'], $item['referer']);
        }

        if ($imgData !== false && strlen($imgData) > 500) {
            $ext = 'jpg';
            if (substr($imgData, 0, 4) === "\x89PNG") {
                $ext = 'png';
            } elseif (substr($imgData, 0, 3) === "GIF") {
                $ext = 'gif';
            } elseif (substr($imgData, 8, 4) === "WEBP" || strpos(substr($imgData, 0, 20), 'WEBP') !== false) {
                $ext = 'webp';
            }

            $fileName = 'page_' . $imgIndex . '.' . $ext;
            $savePath = $articleDir . $fileName;
            file_put_contents($savePath, $imgData);

            // Cập nhật đường dẫn nội bộ chính xác
            $item['node']->setAttribute('src', 'assets/' . $folderName . '/' . $fileName);
            $item['node']->setAttribute('style', 'max-width: 100% !important; height: auto !important; display: block !important; opacity: 1 !important; visibility: visible !important; margin: 0 auto 6px auto !important;');

            $attributesToRemove = ['srcset', 'data-src', 'data-srcset', 'data-original', 'data-cdn', 'data-actualsrc', 'data-href', 'loading', 'class'];
            foreach ($attributesToRemove as $attr) {
                if ($item['node']->hasAttribute($attr)) {
                    $item['node']->removeAttribute($attr);
                }
            }
            $imgIndex++;
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
}

function extractRealImgUrl($node, $baseUrl) {
    $candidates = [
        $node->getAttribute('data-src'),
        $node->getAttribute('data-original'),
        $node->getAttribute('data-cdn'),
        $node->getAttribute('data-actualsrc'),
        $node->getAttribute('data-href'),
        $node->getAttribute('srcset'),
        $node->getAttribute('src')
    ];

    $rawUrl = '';
    foreach ($candidates as $cand) {
        if (!empty($cand)) {
            $rawUrl = trim($cand);
            break;
        }
    }

    if (empty($rawUrl) || strpos($rawUrl, 'data:image') === 0) return '';

    if (strpos($rawUrl, ',') !== false) {
        $parts = explode(',', $rawUrl);
        $rawUrl = trim(explode(' ', trim($parts[0]))[0]);
    }

    if (strpos($rawUrl, '//') === 0) {
        return 'https:' . $rawUrl;
    } elseif (strpos($rawUrl, 'http') !== 0) {
        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? 'localhost';
        return $scheme . '://' . $host . '/' . ltrim($rawUrl, '/');
    }

    return $rawUrl;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_page') {
    $url = trim($_POST['url']);
    $rawTags = trim($_POST['tags'] ?? '');
    $collection_name = !empty($_POST['collection_name']) ? trim($_POST['collection_name']) : 'Chung';
    $category = 'all';

    if (!empty($url)) {
        
        $stmtCheck = $conn->prepare("SELECT id FROM articles WHERE user_id = ? AND url = ?");
        $stmtCheck->bind_param("is", $user_id, $url);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();

        $existingId = 0;
        $newVersion = 1;

        if ($resCheck->num_rows > 0) {
            $existingArticle = $resCheck->fetch_assoc();
            $existingId = $existingArticle['id'];

            $stmtVer = $conn->prepare("SELECT MAX(version) as max_version FROM snapshots WHERE article_id = ?");
            $stmtVer->bind_param("i", $existingId);
            $stmtVer->execute();
            $rowVer = $stmtVer->get_result()->fetch_assoc();
            $newVersion = ($rowVer['max_version'] ? (int)$rowVer['max_version'] : 0) + 1;
        }

        $htmlContent = false;
        $httpCode = 0;
        
        $res = executeCurlRequest($url, $url, false);
        $htmlContent = $res['data'];
        $httpCode = $res['code'];

        if ($httpCode !== 200 && $httpCode !== 304) {
            $resMob = executeCurlRequest($url, $url, true);
            if ($resMob['code'] === 200 || $resMob['code'] === 304) {
                $htmlContent = $resMob['data'];
                $httpCode = $resMob['code'];
            }
        }

        if ($htmlContent !== false && ($httpCode === 200 || $httpCode === 304)) {
            libxml_use_internal_errors(true);
            $doc = new DOMDocument();
            @$doc->loadHTML(mb_convert_encoding($htmlContent, 'HTML-ENTITIES', 'UTF-8'));
            libxml_clear_errors();

            $titleNode = $doc->getElementsByTagName('title');
            $pageTitle = ($titleNode->length > 0) ? trim($titleNode->item(0)->nodeValue) : "Bài lưu từ " . $url;
            $xpath = new DOMXPath($doc);

            // 1. Lọc rác an toàn (không xóa nhầm thẻ chứa nội dung)
            $junkNodes = $xpath->query('//script | //style | //header | //footer | //nav | //iframe | //aside | //*[contains(@class, "comment")] | //*[contains(@class, "breadcrumb")] | //*[contains(@class, "server")] | //*[contains(@class, "btn-error")] | //*[contains(@class, "alert")] | //*[contains(@class, "ads")] | //*[contains(@class, "quang-cao")]');
            foreach ($junkNodes as $node) {
                if ($node->parentNode) $node->parentNode->removeChild($node);
            }

            // Lọc Inline Event JS
            $allElements = $doc->getElementsByTagName('*');
            foreach ($allElements as $element) {
                $attrsToRemove = [];
                if ($element->hasAttributes()) {
                    foreach ($element->attributes as $attr) {
                        if (strpos(strtolower($attr->name), 'on') === 0 || strpos(strtolower($attr->value), 'javascript:') !== false) {
                            $attrsToRemove[] = $attr->name;
                        }
                    }
                    foreach ($attrsToRemove as $attrName) {
                        $element->removeAttribute($attrName);
                    }
                }
            }

            // 2. Tải toàn bộ ảnh trên trang chính để đảm bảo không bị vỡ ảnh
            if ($existingId == 0) {
                $stmt = $conn->prepare("INSERT INTO articles (user_id, title, url, tags, category, collection_name, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssssi", $user_id, $pageTitle, $url, $rawTags, $category, $collection_name, $httpCode);
                $stmt->execute();
                $existingId = $conn->insert_id;
            }

            $folderName = 'item_' . $existingId . '_v' . $newVersion;
            $articleDir = '../assets/' . $folderName . '/';
            if (!file_exists($articleDir)) {
                mkdir($articleDir, 0777, true);
            }

            $imgNodes = $doc->getElementsByTagName('img');
            $imgTasks = [];

            foreach ($imgNodes as $img) {
                $realSrc = extractRealImgUrl($img, $url);
                if (!empty($realSrc)) {
                    $imgTasks[] = [
                        'src' => $realSrc,
                        'node' => $img,
                        'referer' => $url
                    ];
                }
            }

            downloadImagesMulti($imgTasks, $articleDir, $folderName);

            // 3. Bóc tách nội dung HTML sau khi đã chuyển đổi ảnh local thành công
            $targetNodes = $xpath->query('//div[@id="chapter-content"] | //div[@id="chapter-text"] | //div[contains(@class, "reading-loc-content")] | //div[contains(@class, "page-chapter")] | //div[contains(@class, "box-doc-truyen")] | //*[contains(@class, "chapter-content") or contains(@class, "reading-detail") or contains(@class, "story-detail-content") or contains(@class, "article-content") or contains(@class, "detail-content") or contains(@class, "post-content") or contains(@class, "fck_detail") or contains(@class, "content-detail")]');

            $cleanBody = '';
            if ($targetNodes->length > 0) {
                foreach ($targetNodes as $node) {
                    $cleanBody .= $doc->saveHTML($node);
                }
            } else {
                $bodyNode = $doc->getElementsByTagName('body')->item(0);
                $cleanBody = $bodyNode ? $doc->saveHTML($bodyNode) : "<p>Nội dung trang trống.</p>";
            }

            $stmtSnap = $conn->prepare("INSERT INTO snapshots (article_id, version, content) VALUES (?, ?, ?)");
            $stmtSnap->bind_param("iis", $existingId, $newVersion, $cleanBody);
            $stmtSnap->execute();

            $stmtUpdateDate = $conn->prepare("UPDATE articles SET created_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmtUpdateDate->bind_param("i", $existingId);
            $stmtUpdateDate->execute();
        } else {
            $errTitle = "Bài viết lỗi / 404 Not Found";
            $errContent = "<p class='text-danger fw-bold'>HTTP 404 Error: Trang nguồn [$url] không tồn tại hoặc bị hỏng.</p>";
            $errStatus = 404;
            $errCat = "error";
            $errTags = "Lỗi";

            $stmt = $conn->prepare("INSERT INTO articles (user_id, title, url, tags, category, collection_name, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssssi", $user_id, $errTitle, $url, $rawTags, $category, $collection_name, $errStatus);
            $stmt->execute();
            $newArticleId = $conn->insert_id;

            $stmtSnap = $conn->prepare("INSERT INTO snapshots (article_id, version, content) VALUES (?, 1, ?)");
            $stmtSnap->bind_param("is", $newArticleId, $errContent);
            $stmtSnap->execute();
        }
    }
}

header("Location: ../index.php");
exit();
?>