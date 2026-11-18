<?php
// actions/refetch.php - Xử lý cào lại bài viết để tạo Phiên bản mới (New Snapshot)
require_once '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refetch_page') {
    $pageId = (int)$_POST['page_id'];

    $stmtArticle = $conn->prepare("SELECT url FROM articles WHERE id = ?");
    $stmtArticle->bind_param("i", $pageId);
    $stmtArticle->execute();
    $resArticle = $stmtArticle->get_result();

    if ($rowArticle = $resArticle->fetch_assoc()) {
        $url = $rowArticle['url'];

        $stmtVer = $conn->prepare("SELECT MAX(version) as max_version FROM snapshots WHERE article_id = ?");
        $stmtVer->bind_param("i", $pageId);
        $stmtVer->execute();
        $resVer = $stmtVer->get_result();
        $rowVer = $resVer->fetch_assoc();
        $newVersion = ($rowVer['max_version'] ? (int)$rowVer['max_version'] : 0) + 1;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_REFERER, $url);
        $htmlContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($htmlContent !== false && $httpCode === 200) {
            libxml_use_internal_errors(true);
            $doc = new DOMDocument();
            @$doc->loadHTML(mb_convert_encoding($htmlContent, 'HTML-ENTITIES', 'UTF-8'));
            libxml_clear_errors();

            $xpath = new DOMXPath($doc);

            $junkNodes = $xpath->query('//script | //style | //header | //footer | //nav | //iframe | //aside | //*[contains(@class, "comment")]');
            foreach ($junkNodes as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }

            // Xóa sự kiện JS độc hại
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

            $folderName = 'item_' . $pageId . '_v' . $newVersion;
            $articleDir = '../assets/' . $folderName . '/';
            if (!file_exists($articleDir)) {
                mkdir($articleDir, 0777, true);
            }

            $images = $doc->getElementsByTagName('img');
            $imgIndex = 1;

            foreach ($images as $img) {
                $realSrc = $img->getAttribute('data-original') 
                        ?: $img->getAttribute('data-src') 
                        ?: $img->getAttribute('data-cdn')
                        ?: $img->getAttribute('srcset')
                        ?: $img->getAttribute('src');

                if (strpos($realSrc, ',') !== false) {
                    $parts = explode(',', $realSrc);
                    $realSrc = trim(explode(' ', trim($parts[0]))[0]);
                }

                if (!empty($realSrc) && strpos($realSrc, 'data:image') === false) {
                    if (strpos($realSrc, '//') === 0) {
                        $realSrc = 'https:' . $realSrc;
                    } elseif (strpos($realSrc, 'http') !== 0) {
                        $parsedUrl = parse_url($url);
                        $baseUrl = ($parsedUrl['scheme'] ?? 'http') . '://' . ($parsedUrl['host'] ?? 'localhost');
                        $realSrc = $baseUrl . '/' . ltrim($realSrc, '/');
                    }

                    $imgCh = curl_init($realSrc);
                    curl_setopt($imgCh, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($imgCh, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($imgCh, CURLOPT_TIMEOUT, 10);
                    curl_setopt($imgCh, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
                    curl_setopt($imgCh, CURLOPT_REFERER, $url);
                    $imgData = curl_exec($imgCh);
                    $imgHttpCode = curl_getinfo($imgCh, CURLINFO_HTTP_CODE);
                    curl_close($imgCh);

                    if ($imgData !== false && $imgHttpCode === 200) {
                        $ext = 'jpg';
                        if (strpos($realSrc, '.png') !== false) $ext = 'png';
                        if (strpos($realSrc, '.webp') !== false) $ext = 'webp';

                        $fileName = 'page_' . $imgIndex . '.' . $ext;
                        $localPath = $articleDir . $fileName;
                        file_put_contents($localPath, $imgData);

                        $img->setAttribute('src', 'assets/' . $folderName . '/' . $fileName);
                        $img->setAttribute('style', 'max-width: 100%; height: auto; display: block; margin: 10px auto;');
                        $img->removeAttribute('loading');
                        $img->removeAttribute('data-src');
                        $img->removeAttribute('data-original');
                        $img->removeAttribute('srcset');
                        $imgIndex++;
                    }
                }
            }

            $storyNodes = $xpath->query('//article | //*[contains(@class, "story-detail-content") or contains(@class, "reading-detail") or contains(@class, "chapter-content") or contains(@class, "article-content") or contains(@class, "detail-content") or contains(@class, "post-content") or contains(@class, "fck_detail") or contains(@class, "content-detail")]');
            if ($storyNodes->length > 0) {
                $cleanBody = '';
                foreach ($storyNodes as $node) {
                    $cleanBody .= $doc->saveHTML($node);
                }
            } else {
                $bodyNode = $doc->getElementsByTagName('body')->item(0);
                $cleanBody = $bodyNode ? $doc->saveHTML($bodyNode) : "<p>Nội dung trang trống.</p>";
            }

            $stmtSnap = $conn->prepare("INSERT INTO snapshots (article_id, version, content) VALUES (?, ?, ?)");
            $stmtSnap->bind_param("iis", $pageId, $newVersion, $cleanBody);
            $stmtSnap->execute();

            $stmtUpdateStatus = $conn->prepare("UPDATE articles SET status = 200 WHERE id = ?");
            $stmtUpdateStatus->bind_param("i", $pageId);
            $stmtUpdateStatus->execute();
        } else {
            $errContent = "<p class='text-danger fw-bold'>[Lần cào v{$newVersion} thất bại] HTTP Error {$httpCode}: Không thể lấy nội dung trang gốc tại thời điểm này.</p>";
            
            $stmtSnap = $conn->prepare("INSERT INTO snapshots (article_id, version, content) VALUES (?, ?, ?)");
            $stmtSnap->bind_param("iis", $pageId, $newVersion, $errContent);
            $stmtSnap->execute();
        }
    }

    $stmtUpdateDate = $conn->prepare("UPDATE articles SET created_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmtUpdateDate->bind_param("i", $pageId);
    $stmtUpdateDate->execute();
}

header("Location: ../index.php");
exit();
?>