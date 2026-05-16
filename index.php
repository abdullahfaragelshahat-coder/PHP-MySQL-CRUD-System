<?php
// تضمين ملف الاتصال بقاعدة البيانات لكي نتمكن من استخدام المتغير $conn
include 'db.php';

$msg = "";
$error = "";

// متغيرات لتخزين بيانات المنتج مؤقتاً عند تعديله
$id = ""; $name = ""; $price = ""; $image_url = "";
$update_mode = false;

// ==========================================
// 1. عمليات الإنشاء والتعديل (Create & Update)
// ==========================================
if (isset($_POST['save'])) {
    $name = trim($_POST['name']);
    $price = trim($_POST['price']);
    $id = $_POST['id'];

    // --- [Validation] التحقق من صحة البيانات المدخلة ---
    if (empty($name) || empty($price)) {
        $error = "جميع الحقول النصية مطلوبة!";
    } elseif (!is_numeric($price) || $price <= 0) {
        $error = "يجب أن يكون السعر رقماً موجباً أكبر من الصفر!";
    } else {
        
        // تعامل خاص مع ملف الصورة المرفوع
        $image_name = $_FILES['image']['name'];
        $image_tmp = $_FILES['image']['tmp_name'];
        $image_size = $_FILES['image']['size'];
        
        if (!empty($image_name)) {
            // التحقق من صيغة وامتداد الصورة لحماية السيرفر
            $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
            $ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
            
            if (!in_array($ext, $allowed_ext)) {
                $error = "صيغة الصورة غير مسموحة! المسموح فقط: jpg, jpeg, png, webp";
            } elseif ($image_size > 2 * 1024 * 1024) { // الحد الأقصى 2 ميجابايت
                $error = "حجم الصورة كبير جداً! الحد الأقصى المسموح به هو 2 ميجابايت";
            } else {
                // إنشاء مجلد uploads تلقائياً على السيرفر إن لم يكن موجوداً
                if (!is_dir('uploads')) { mkdir('uploads'); }
                
                // توليد اسم عشوائي وفريد للصورة لمنع تداخل واستبدال الملفات المتشابهة الاسماء
                $new_image_name = time() . '_' . uniqid() . '.' . $ext;
                $target_path = 'uploads/' . $new_image_name;
                
                if (move_uploaded_file($image_tmp, $target_path)) {
                    // في حالة "التعديل" ورفع صورة جديدة، نقوم بحذف الصورة القديمة من السيرفر لتوفير المساحة
                    if (!empty($id)) {
                        $stmt = $conn->prepare("SELECT image_url FROM products WHERE id = ?");
                        $stmt->execute([$id]);
                        $old_img = $stmt->fetchColumn();
                        if (file_exists($old_img)) { unlink($old_img); }
                    }
                    $image_url = $target_path;
                }
            }
        }

        // إذا نجحت عمليات التحقق ولم يكن هناك أي خطأ، نبدأ بالتعامل مع قاعدة البيانات
        if (empty($error)) {
            if (empty($id)) { 
                // --- [Create] وضع الإنشاء الجديد ---
                if (empty($image_url)) {
                    $error = "يجب رفع صورة للمنتج الجديد!";
                } else {
                    $stmt = $conn->prepare("INSERT INTO products (name, price, image_url) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $price, $image_url]);
                    $msg = "تم إضافة المنتج بنجاح!";
                    $name = ""; $price = ""; // تفريغ الحقول بعد النجاح
                }
            } else { 
                // --- [Update] وضع تعديل منتج قائم ---
                if (!empty($image_url)) { // إذا اختار المستخدم صورة جديدة
                    $stmt = $conn->prepare("UPDATE products SET name = ?, price = ?, image_url = ? WHERE id = ?");
                    $stmt->execute([$name, $price, $image_url, $id]);
                } else { // إذا لم يقم بتغيير الصورة واحتفظ بالقديمة
                    $stmt = $conn->prepare("UPDATE products SET name = ?, price = ? WHERE id = ?");
                    $stmt->execute([$name, $price, $id]);
                }
                $msg = "تم تحديث بيانات المنتج بنجاح!";
                $name = ""; $price = ""; $id = ""; // إعادة تعيين المتغيرات للحالة الافتراضية
            }
        }
    }
}

// ==========================================
// 2. جلب البيانات لوضع التعديل (Edit Mode)
// ==========================================
if (isset($_GET['edit'])) {
    $id = $_GET['edit'];
    $update_mode = true;
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $product = $stmt->fetch();
    if ($product) {
        $name = $product['name'];
        $price = $product['price'];
        $image_url = $product['image_url'];
    }
}

// ==========================================
// 3. عملية الحذف النهائي (Delete)
// ==========================================
if (isset($_GET['delete'])) {
    $del_id = $_GET['delete'];
    
    // جلب مسار الصورة من قاعدة البيانات لحذفها فيزيائياً من مجلد السيرفر أولاً
    $stmt = $conn->prepare("SELECT image_url FROM products WHERE id = ?");
    $stmt->execute([$del_id]);
    $img = $stmt->fetchColumn();
    if (file_exists($img)) {
        unlink($img); // دالة تحذف الملف نهائياً من القرص الصلب لتوفير المساحة
    }
    
    // حذف سجل المنتج من قاعدة البيانات
    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$del_id]);
    header("Location: index.php?msg=تم حذف المنتج بنجاح");
    exit();
}

// ==========================================
// 4. عمليات القراءة، البحث، وتصفيف الصفحات (Read, Search & Pagination)
// ==========================================
$limit = 3; // حددنا ظهور 3 منتجات فقط كحد أقصى في الصفحة الواحدة
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) { $page = 1; }
$offset = ($page - 1) * $limit; // معادلة ذكية لتحديد عدد المنتجات المراد تخطيها

$search = "";
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
    
    // حساب إجمالي المنتجات المطابقة للبحث فقط لمعرفة كم صفحة نحتاج
    $count_stmt = $conn->prepare("SELECT COUNT(*) FROM products WHERE name LIKE ? OR price LIKE ?");
    $count_stmt->execute(["%$search%", "%$search%"]);
    $total_products = $count_stmt->fetchColumn();
    $total_pages = ceil($total_products / $limit);

    // جلب منتجات الصفحة الحالية المتوافقة مع كلمة البحث
    $stmt = $conn->prepare("SELECT * FROM products WHERE name LIKE ? OR price LIKE ? ORDER BY id DESC LIMIT $limit OFFSET $offset");
    $stmt->execute(["%$search%", "%$search%"]);
} else {
    // حساب إجمالي كل المنتجات المتواجدة في قاعدة البيانات
    $total_products = $conn->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $total_pages = ceil($total_products / $limit); // دالة ceil تقرب الكسر العشري لأعلى رقم صحيح

    // جلب منتجات الصفحة الحالية فقط بترتيب من الأحدث للأقدم
    $stmt = $conn->query("SELECT * FROM products ORDER BY id DESC LIMIT $limit OFFSET $offset");
}
$products = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>نظام إدارة المنتجات الاحترافي</title>
    <style>
        /* كود الـ CSS لتنسيق وتنظيم واجهة المستخدم لتصبح مريحة للعين */
        body { font-family: Arial, sans-serif; background-color: #f4f7f6; margin: 20px; color: #333; }
        .container { max-width: 900px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h2, h3 { text-align: center; color: #007bff; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="file"], input[type="number"] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .btn { background: #28a745; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; width: 100%; font-size: 16px; }
        .btn-edit { background: #ffc107; color: black; padding: 5px 10px; text-decoration: none; border-radius: 4px; font-size: 14px; }
        .btn-delete { background: #dc3545; color: white; padding: 5px 10px; text-decoration: none; border-radius: 4px; font-size: 14px; }
        .search-box { display: flex; margin-bottom: 20px; gap: 10px; }
        .search-box input { flex: 1; }
        .search-box button { background: #007bff; color: white; border: none; padding: 0 20px; border-radius: 4px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: center; }
        th { background-color: #f2f2f2; }
        .alert { padding: 10px; margin-bottom: 15px; border-radius: 4px; text-align: center; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-danger { background: #f8d7da; color: #721c24; }
        .prod-img { width: 60px; height: 60px; object-fit: cover; border-radius: 4px; }
        .pagination-nav a { padding: 8px 12px; border: 1px solid #007bff; text-decoration: none; border-radius: 4px; color: #007bff; }
        .pagination-nav a.active { background: #007bff; color: white; }
    </style>
</head>
<body>

<div class="container">
    <h2>📦 لوحة تحكم المستودع الكاملة</h2>

    <?php if(!empty($msg) || isset($_GET['msg'])): ?>
        <div class="alert alert-success"><?= $msg ? $msg : htmlspecialchars($_GET['msg']) ?></div>
    <?php endif; ?>
    <?php if(!empty($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <form action="index.php" method="POST" enctype="multipart/form-data" style="background: #f9f9f9; padding: 15px; border-radius: 6px; margin-bottom: 30px;">
        <h3><?= $update_mode ? "تعديل المنتج الحالي ✏️" : "إضافة منتج جديد للمخزن ➕" ?></h3>
        <input type="hidden" name="id" value="<?= $id ?>">
        
        <div class="form-group">
            <label>اسم المنتج:</label>
            <input type="text" name="name" value="<?= htmlspecialchars($name) ?>">
        </div>
        <div class="form-group">
            <label>السعر الحقيقي ($):</label>
            <input type="number" step="0.01" name="price" value="<?= htmlspecialchars($price) ?>">
        </div>
        <div class="form-group">
            <label>صورة المنتج التوضيحية:</label>
            <input type="file" name="image">
            <?php if($update_mode && !empty($image_url)): ?>
                <p style="margin-top:5px;">الصورة الحالية المخزنة: <img src="<?= $image_url ?>" class="prod-img" style="vertical-align: middle;"></p>
            <?php endif; ?>
        </div>
        <button type="submit" name="save" class="btn" style="<?= $update_mode ? 'background:#ffc107; color:black;' : '' ?>">
            <?= $update_mode ? "تحديث البيانات الآن" : "حفظ المنتج في القاعدة" ?>
        </button>
        <?php if($update_mode): ?>
            <a href="index.php" style="display:block; text-align:center; margin-top:10px; color:#666; text-decoration:none;">❌ إلغاء عملية التعديل</a>
        <?php endif; ?>
    </form>

    <form action="index.php" method="GET" class="search-box">
        <input type="text" name="search" placeholder="ابحث عن منتج محدد بالاسم أو السعر..." value="<?= htmlspecialchars($search) ?>">
        <button type="submit">بحث 🔍</button>
        <?php if(!empty($search)): ?>
            <a href="index.php" style="padding:10px; background:#6c757d; color:white; text-decoration:none; border-radius:4px; line-height:20px;">إلغاء التصفية</a>
        <?php endif; ?>
    </form>

    <table>
        <thead>
            <tr>
                <th>المعرف (ID)</th>
                <th>الصورة</th>
                <th>اسم المنتج</th>
                <th>السعر</th>
                <th>خيارات التحكم</th>
            </tr>
        </thead>
        <tbody>
            <?php if(count($products) > 0): ?>
                <?php foreach($products as $row): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td><img src="<?= $row['image_url'] ?>" class="prod-img" alt="لا توجد صورة للمنتج"></td>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td>$<?= htmlspecialchars($row['price']) ?></td>
                        <td>
                            <a href="index.php?edit=<?= $row['id'] ?>" class="btn-edit">تعديل</a>
                            <a href="index.php?delete=<?= $row['id'] ?>" class="btn-delete" onclick="return confirm('هل أنت متأكد تماماً من حذف هذا المنتج ونقل صورته لسلة المهملات؟')">حذف</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5">المخزن فارغ حالياً أو لا توجد نتائج تطابق كلمة البحث!</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
        <div class="pagination-nav" style="display: flex; justify-content: center; margin-top: 20px; gap: 5px;">
            
            <?php if($page > 1): ?>
                <a href="index.php?page=<?= $page - 1 ?><?= !empty($search) ? '&search='.urlencode($search) : '' ?>">السابق</a>
            <?php endif; ?>

            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <a href="index.php?page=<?= $i ?><?= !empty($search) ? '&search='.urlencode($search) : '' ?>" 
                   class="<?= $page == $i ? 'active' : '' ?>">
                   <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php if($page < $total_pages): ?>
                <a href="index.php?page=<?= $page + 1 ?><?= !empty($search) ? '&search='.urlencode($search) : '' ?>">التالي</a>
            <?php endif; ?>

        </div>
    <?php endif; ?>
</div>

</body>
</html>