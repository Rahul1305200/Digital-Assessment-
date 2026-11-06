<?php
declare(strict_types=1);

// Helper functions carried over from the first version (api.php), unchanged apart from plumbing.
// Loaded through composer "autoload.files".

use App\Support\ApiStop;
use App\Support\Ctx;

function theAudio(): int { return (int)query('SELECT id FROM audio_files ORDER BY id LIMIT 1')->fetchColumn(); }
function respond($data, int $status=200): never { throw new ApiStop(Ctx::json($data,$status)); }
function fail(string $message,int $status=400): never { respond(['error'=>$message],$status); }
function staff(): void { if (empty(Ctx::$S['staff'])) fail('Please sign in to continue.',401); }
function query(string $sql,array $args=[]): PDOStatement { $s=Ctx::$db->prepare($sql);$s->execute($args);return $s; }
function audit(?int $id,string $action,array $details=[]): void { query('INSERT INTO audit(participant_id,actor,action,details) VALUES (?,?,?,?)',[$id,Ctx::$S['staff']??'participant',$action,json_encode($details)]); }
function seal(string $value): string { $key=file_get_contents(storage_path('app/private/key'));$iv=random_bytes(12);$tag='';$enc=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);return base64_encode($iv.$tag.$enc); }
function unseal(string $value): string { $raw=base64_decode($value);return openssl_decrypt(substr($raw,28),'aes-256-gcm',file_get_contents(storage_path('app/private/key')),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16)); }
function patient(bool $lock=false): array { $token=$_SERVER['HTTP_X_PARTICIPANT']??($_GET['token']??''); if(!preg_match('/^[a-f0-9]{64}$/',$token)) fail('This participant link is invalid.',404);$p=query('SELECT * FROM participants WHERE token_hash=?'.($lock?' FOR UPDATE':''),[hash('sha256',$token)])->fetch(); if(!$p) fail('This participant link is invalid.',404);return $p; }
function visible(array $q,array $a,string $group): bool { if(isset($q['group'])&&$q['group']!==$group)return false;if(!isset($q['when']))return true;$v=$a[$q['when']['field']]??null;return count(array_intersect(is_array($v)?$v:[$v],$q['when']['values']))>0; }
function snapshot(array $p,bool $admin=false): array {
 $rows=query('SELECT type,status,answers,missing,core_completed,entry_method,instrument_version,closure_reason,revision,opened_at,saved_at,submitted_at FROM assessments WHERE participant_id=?',[$p['id']])->fetchAll();
 foreach($rows as &$r){$r['answers']=json_decode($r['answers'],true);$r['missing']=json_decode($r['missing']??'{}',true);}unset($r);
 $p['assessments']=$rows;unset($p['token_hash']);if($admin){$p['token']=unseal($p['token_cipher']);$op=query('SELECT record,revision FROM operational_records WHERE participant_id=?',[$p['id']])->fetch();$p['operations']=$op?json_decode($op['record'],true):new stdClass();$p['operations_revision']=$op?(int)$op['revision']:0;}unset($p['token_cipher']);
 if(!$admin){unset($p['session_note']);if($p['study_group']==='unassigned')$p['study_group']=null;$aid=$p['audio_id']?:$p['sleep_audio_id'];$p['audio']=$aid?(query('SELECT title,description FROM audio_files WHERE id=?',[$aid])->fetch()?:null):null;}
 return $p;
}