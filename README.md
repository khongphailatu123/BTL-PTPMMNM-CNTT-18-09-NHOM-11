[README.md](https://github.com/user-attachments/files/34118698/README.md)
# 🔖 Pocket Saver (pocket_app2) - Hệ Thống Trích Xuất & Lưu Trữ Web Offline

> **Đề tài bài tập lớn / Đồ án môn học:** Xây dựng ứng dụng Web cào dữ liệu, lưu trữ trang web offline, hỗ trợ đa phiên bản, phân quyền người dùng và chia sẻ nội bộ.

---

## 📌 1. Giới Thiệu Tổng Quan

**Pocket Saver** là ứng dụng web được phát triển trên nền tảng **PHP & MySQL**, lấy cảm hứng từ ứng dụng Pocket nổi tiếng. Hệ thống giúp người dùng lưu trữ lại các bài báo, chương truyện (Light Novel, Truyện tranh), tài liệu nghiên cứu hoặc bài viết kỹ thuật từ bất kỳ đường dẫn URL nào trên Internet.

Điểm nổi bật của Pocket Saver là khả năng **cào và lưu trữ dữ liệu hoàn toàn về máy cục bộ (Offline Snapshotting)**, tự động tải và tổ chức toàn bộ tệp hình ảnh về máy chủ local, lọc bỏ các thành phần rác (quảng cáo, script độc hại, popup, bình luận), cho phép đọc nội dung với giao diện tối (Dark Mode) tối ưu cho thị giác mà không phụ thuộc vào kết nối mạng của trang web gốc.

---

## ✨ 2. Các Tính Năng Nổi Bật

### 🛡️ 2.1. Phân Quyền Người Dùng (Role-Based Access Control - RBAC)
* **Người dùng (`user`):** Quản lý bộ sưu tập cá nhân (cào bài, chỉnh sửa nhãn, ghi chú, xem lịch sử phiên bản, chia sẻ bài viết cho bạn bè).
* **Quản trị viên (`admin`):**
  * Truy cập Bảng Quản Trị Hệ Thống (`admin.php`).
  * Theo dõi danh sách toàn bộ tài khoản và tổng số bài viết cào được của từng tài khoản.
  * Cấp quyền Admin hoặc thu hồi quyền của người dùng khác.
  * Quản lý và xóa tài khoản vi phạm.

### 🌐 2.2. Trích Xuất & Lưu Trữ Offline Cực Mạnh (Web Crawler Engine)
* **Cào dữ liệu đa luồng (cURL Multi-handle):** Tải nội dung và hình ảnh bài viết tốc độ cao.
* **Xử lý ảnh cục bộ:** Tự động phát hiện ảnh (kể cả dạng `data-src`, `lazy-loading`, `srcset`), tải về thư mục `assets/` và đổi lại đường dẫn nội bộ để đọc mượt mà khi không có mạng.
* **An toàn & Bảo mật (Anti-XSS & SSRF Prevention):** Tự động loại bỏ thẻ `<script>`, `<style>`, `<iframe>`, các sự kiện JavaScript inline (`onclick`, `onload`...) và các quảng cáo gây phiền phức.
* **Nhận diện trạng thái HTTP:** Đánh dấu trực quan các trang bị lỗi **HTTP 404 / 403 / 500** với giao diện cảnh báo riêng.

### 📜 2.3. Quản Lý Đa Phiên Bản (Snapshot Versioning)
* Cho phép người dùng cào lại một trang web theo thời gian (v1, v2, v3...).
* Dễ dàng chuyển đổi qua lại giữa các phiên bản snapshot để theo dõi sự thay đổi nội dung của trang gốc.

### 💬 2.4. Ghi Chú Đa Người Dùng (Interactive Notes)
* Cho phép viết ghi chú trực tiếp vào từng bài viết / phiên bản.
* Tự động nhận diện và đính kèm tên tài khoản người viết (`Username: Nội dung ghi chú`), giúp phân biệt rõ ràng giữa người gửi và người nhận khi bài viết được chia sẻ.

### 🤝 2.5. Chia Sẻ Bài Viết & Luồng Duyệt Thông Báo (Sharing & Approval Flow)
* **Chia sẻ nội bộ:** Gửi bài viết trực tiếp cho người dùng khác trong cùng hệ thống.
* **Thông báo Quả Chuông (🔔 Badge):** Người nhận sẽ thấy số lượng thông báo bài viết gửi tới trên thanh Header.
* **Luồng phê duyệt (`Pending` $\rightarrow$ `Accepted` / `Rejected`):** Bài viết chỉ xuất hiện trong thư viện cá nhân của người nhận khi họ bấm **Chấp nhận**.
* **Chia sẻ công khai:** Xuất đường dẫn liên kết công khai (`share.php`) hoặc xuất file HTML độc lập (`export.php`).

### 🏷️ 2.6. Tổ Chức Thư Viện, Đa Nhãn & Tìm Kiếm Trực Tiếp
* **Hỗ trợ đa nhãn (Multi-Tag):** Nhập nhiều nhãn cách nhau bằng dấu phẩy (vd: `Manga, Chuyển sinh, Action`). Tự động chuẩn hóa và phân loại thành các thẻ badge `#tag`.
* **Bộ sưu tập (Collections):** Quản lý bài viết theo dạng thư viện thư mục trực quan.
* **Tìm kiếm nội dung sâu (Full-text Search):** Tìm kiếm tức thì theo tiêu đề, đường dẫn URL, thẻ nhãn hoặc trích xuất đoạn văn bản trùng khớp trong nội dung bài viết.

---

## 🛠️ 3. Công Nghệ Sử Dụng

* **Backend:** PHP 8.x (mysqli, cURL, DOMDocument, DOMXPath, libxml)
* **Database:** MySQL / MariaDB (Hỗ trợ UTF-8MB4)
* **Frontend:** HTML5, CSS3, JavaScript (ES6+), Bootstrap 5.3, FontAwesome 6
* **Thư viện bổ trợ:** SweetAlert2 (Thông báo popup hiện đại)
* **Môi trường thử nghiệm:** XAMPP Server / WAMP / Localhost

---

## 📂 4. Cấu Trúc Thư Mục Dự Án

```text
pocket_app2/
├── actions/                  # Các file xử lý Logic (Backend API)
│   ├── delete.php            # Xóa bài viết và thư mục ảnh liên quan
│   ├── edit.php              # Chỉnh sửa Tiêu đề, URL, Tags, Collection
│   ├── export.php            # Xuất bài viết ra file HTML tải về
│   ├── note.php              # Thêm ghi chú kèm tên người dùng
│   ├── refetch.php           # Cào cập nhật tạo phiên bản mới (v2, v3...)
│   ├── respond_share.php     # Chấp nhận / Từ chối lời mời chia sẻ
│   ├── save.php              # Bộ cào dữ liệu chính & tải ảnh cURL multi
│   └── share_internal.php    # Gửi yêu cầu chia sẻ bài viết cho tài khoản khác
├── assets/                   # Thư mục lưu trữ hình ảnh cào về (item_ID_vX)
├── config/                   # Cấu hình kết nối Cơ sở dữ liệu
│   └── db.php                # Kết nối MySQLi
├── admin.php                 # Bảng Quản Trị Hệ Thống (Dành cho Admin)
├── index.php                 # Giao diện chính của ứng dụng
├── login.php                 # Trang Đăng nhập
├── register.php              # Trang Đăng ký tài khoản
├── logout.php                # Đăng xuất hệ thống
├── share.php                 # Trang xem bài viết chia sẻ công khai
└── pocket_db2.sql            # File kịch bản khởi tạo Cơ sở dữ liệu MySQL
