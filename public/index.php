<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
const APP_VERSION = '1.0.0';
$cats=['income'=>['salary'=>'Lương / trợ cấp','gift'=>'Quà tặng','other'=>'Thu khác'],
       'expense'=>['food'=>'Ăn uống','travel'=>'Đi lại','study'=>'Học tập','home'=>'Nhà ở','other'=>'Chi khác']];
function fetchRow(PDO $db,string $sql,array $params): array|false {
    $q=$db->prepare($sql);$q->execute($params);return $q->fetch();
}
function stop(int $status,string $message): never {http_response_code($status);exit(e($message));}
function param(array $source,string $key): string {return is_string($source[$key]??null) ? $source[$key] : '';}
function csrf(): string {return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">';}
function money(mixed $n): string {return number_format((int)$n,0,',','.').' ₫';}
$month=param($_GET,'month') ?: date('Y-m');
if (!preg_match('/\A20\d{2}-(0[1-9]|1[0-2])\z/',$month)) stop(400,'Tháng không hợp lệ.');
$next=(new DateTimeImmutable($month.'-01'))->modify('+1 month')->format('Y-m-d');
$uid=(int)($_SESSION['uid']??0);
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals($_SESSION['csrf'],param($_POST,'csrf'))) stop(403,'Phiên biểu mẫu không hợp lệ.');
    $action=param($_POST,'action');
    if ($action==='login' && !$uid) {
        if (time() < (int)($_SESSION['retry_at']??0)) stop(429,'Vui lòng đợi trước khi thử lại.');
        $email=strtolower(trim(param($_POST,'email')));$pw=param($_POST,'password');
        $user=strlen($email)<=254 ? fetchRow($db,'SELECT id,password_hash FROM users WHERE email=?',[$email]) : false;
        $valid=strlen($pw)<=72 && password_verify($pw,$user['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
        if ($user && $valid) {
            if (!session_regenerate_id(true)) stop(503,'Không thể tạo phiên đăng nhập.');
            $_SESSION=['uid'=>(int)$user['id'],'csrf'=>bin2hex(random_bytes(32))];
            redirect();
        }
        $_SESSION['retry_at']=time()+3;
        $error='Email hoặc mật khẩu không đúng.';
    } elseif (!$uid) {
        stop(401,'Cần đăng nhập.');
    } elseif ($action==='logout') {
        $_SESSION=[];session_destroy();
        $c=session_get_cookie_params();
        setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>$c['path'],'secure'=>$c['secure'],'httponly'=>true,'samesite'=>'Lax']);
        redirect();
    } elseif ($action==='delete' || $action==='save') {
        $raw=param($_POST,'id');
        $id=$raw==='' ? 0 : filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id===false || ($action==='delete' && !$id)) stop(400,'Mã giao dịch không hợp lệ.');
        if ($id && !fetchRow($db,'SELECT id FROM entries WHERE id=? AND user_id=?',[$id,$uid])) stop(404,'Không tìm thấy giao dịch.');
        if ($action==='delete') {
            $db->prepare('DELETE FROM entries WHERE id=? AND user_id=?')->execute([$id,$uid]);
            header('Location: index.php?month='.$month,true,303);exit;
        }
        $kind=param($_POST,'kind');$category=param($_POST,'category');$date=param($_POST,'date');
        $amount=param($_POST,'amount');$note=trim(param($_POST,'note'));
        $d=preg_match('/\A20\d{2}-\d{2}-\d{2}\z/',$date) ? DateTimeImmutable::createFromFormat('!Y-m-d',$date) : false;
        if (!isset($cats[$kind][$category]) || !$d || $d->format('Y-m-d')!==$date ||
            !preg_match('/\A[1-9][0-9]{0,12}\z/',$amount) || (int)$amount>1000000000000 || strlen($note)>500) {
            http_response_code(422);$error='Kiểm tra ngày, danh mục và số tiền nguyên dương (tối đa 1.000 tỷ ₫). Ghi chú tối đa 500 byte.';
        } else {
            $v=[$kind,(int)$amount,$category,$date,$note];
            if ($id) $db->prepare('UPDATE entries SET kind=?,amount=?,category=?,occurred_on=?,note=? WHERE id=? AND user_id=?')->execute([...$v,$id,$uid]);
            else $db->prepare('INSERT INTO entries(kind,amount,category,occurred_on,note,user_id) VALUES (?,?,?,?,?,?)')->execute([...$v,$uid]);
            header('Location: index.php?month='.substr($date,0,7),true,303);exit;
        }
    } else stop(400,'Thao tác không hợp lệ.');
}
$edit=false;$rows=[];$total=['income'=>0,'expense'=>0];$breakdown=[];
if ($uid) {
    if (isset($_GET['edit'])) {
        $id=filter_var(param($_GET,'edit'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id || !($edit=fetchRow($db,'SELECT * FROM entries WHERE id=? AND user_id=?',[$id,$uid]))) stop(404,'Không tìm thấy giao dịch.');
    }
    $bounds=[$uid,$month.'-01',$next];
    $q=$db->prepare('SELECT kind,category,SUM(amount) AS subtotal FROM entries WHERE user_id=? AND occurred_on>=? AND occurred_on<? GROUP BY kind,category');
    $q->execute($bounds);
    foreach($q as $item){$total[$item['kind']]+=(int)$item['subtotal'];if($item['kind']==='expense')$breakdown[$item['category']]=(int)$item['subtotal'];}
    $page=filter_var(param($_GET,'page'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]) ?: 1;
    $offset=($page-1)*25;
    $q=$db->prepare('SELECT * FROM entries WHERE user_id=? AND occurred_on>=? AND occurred_on<? ORDER BY occurred_on DESC,id DESC LIMIT 26 OFFSET '.$offset);
    $q->execute($bounds);$rows=$q->fetchAll();
}
$labels=['income'=>'Khoản thu','expense'=>'Khoản chi'];
?>
<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sổ thu chi</title><link rel="stylesheet" href="style.css"></head><body>
<header><div><small>ĐỒ ÁN ĐIỆN TOÁN ĐÁM MÂY</small><h1>Sổ thu chi</h1><p>Ghi lại các khoản thu và chi mỗi ngày</p></div>
<?php if($uid): ?><form method="post"><?=csrf()?><button class="quiet" name="action" value="logout">Đăng xuất</button></form><?php endif; ?></header>
<main><?php if($error): ?><p class="error" role="alert"><?=e($error)?></p><?php endif; ?>
<?php if(!$uid): ?>
<section class="login"><h2>Đăng nhập</h2><form method="post"><?=csrf()?>
<label>Email<input type="email" name="email" maxlength="254" required autocomplete="username"></label>
<label>Mật khẩu<input type="password" name="password" maxlength="72" required autocomplete="current-password"></label>
<button name="action" value="login">Vào sổ thu chi</button></form><p>Tài khoản do người quản trị tạo.</p></section>
<?php else: ?>
<form class="filter" method="get"><label for="month">Tháng báo cáo</label><input id="month" type="month" name="month" value="<?=e($month)?>" required><button>Xem</button></form>
<div class="cards"><article><small>Tổng thu</small><strong class="income" id="total-income"><?=money($total['income'])?></strong></article><article><small>Tổng chi</small><strong class="expense" id="total-expense"><?=money($total['expense'])?></strong></article><article><small>Chênh lệch thu − chi</small><strong id="balance"><?=money($total['income']-$total['expense'])?></strong></article></div>
<div class="split"><section><h2><?=$edit?'Sửa giao dịch':'Ghi giao dịch'?></h2><form method="post"><?=csrf()?><input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
<label>Loại<select name="kind" id="kind"><?php foreach($labels as $k=>$label): ?><option value="<?=$k?>" <?=($edit['kind']??'expense')===$k?'selected':''?>><?=$label?></option><?php endforeach; ?></select></label>
<label>Danh mục<select name="category" id="category"><?php foreach($cats as $k=>$group): foreach($group as $code=>$label): ?><option data-kind="<?=$k?>" value="<?=$code?>" <?=($edit['kind']??'expense')===$k && ($edit['category']??'food')===$code?'selected':''?>><?=e($label)?></option><?php endforeach; endforeach; ?></select></label>
<label>Số tiền (VNĐ)<input type="number" name="amount" min="1" max="1000000000000" step="1" required value="<?=e($edit['amount']??'')?>"></label>
<label>Ngày<input type="date" name="date" required value="<?=e($edit['occurred_on']??date('Y-m-d'))?>"></label>
<label>Ghi chú<input name="note" maxlength="500" value="<?=e($edit['note']??'')?>"></label>
<button name="action" value="save">Lưu giao dịch</button><?php if($edit): ?> <a href="?month=<?=e($month)?>">Hủy sửa</a><?php endif; ?></form></section>
<section><h2>Chi theo danh mục</h2><?php if(!$breakdown): ?><p>Chưa có khoản chi trong tháng.</p><?php endif; ?>
<?php foreach($breakdown as $k=>$n): ?><div class="bar-label"><span><?=e($cats['expense'][$k]??$k)?></span><strong><?=money($n)?></strong></div><meter min="0" max="<?=e(max(1,$total['expense']))?>" value="<?=e($n)?>"></meter><?php endforeach; ?></section></div>
<section><h2>Giao dịch tháng <?=e($month)?></h2><div class="scroll"><table><thead><tr><th>Ngày</th><th>Danh mục</th><th>Ghi chú</th><th>Số tiền</th><th>Thao tác</th></tr></thead><tbody>
<?php foreach(array_slice($rows,0,25) as $r): ?><tr><td><?=e($r['occurred_on'])?></td><td><?=e($cats[$r['kind']][$r['category']]??$r['category'])?></td><td><?=e($r['note'])?></td><td class="<?=e($r['kind'])?>"><?=($r['kind']==='income'?'+':'−').money($r['amount'])?></td><td><a href="?month=<?=e($month)?>&amp;edit=<?=$r['id']?>">Sửa</a><form method="post" class="inline delete"><?=csrf()?><input type="hidden" name="id" value="<?=$r['id']?>"><button class="quiet" name="action" value="delete">Xóa</button></form></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="5">Chưa có giao dịch.</td></tr><?php endif; ?></tbody></table></div>
<nav><?php if($page>1): ?><a href="?month=<?=e($month)?>&amp;page=<?=$page-1?>">Trang trước</a> · <?php endif; ?>Trang <?=$page?><?php if(count($rows)>25): ?> · <a href="?month=<?=e($month)?>&amp;page=<?=$page+1?>">Trang sau</a><?php endif; ?></nav></section>
<?php endif; ?></main><footer>Phiên bản <?=APP_VERSION?> · Máy phục vụ <?=e(gethostname())?></footer><script src="app.js" defer></script></body></html>
