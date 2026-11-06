<?php
declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDO;
use finfo;
use Throwable;

/**
 * The study API. Logic carried over unchanged from the first version (public/api.php);
 * Laravel now provides the database connection, session, config, storage and testing.
 * One endpoint, selected by ?action=..., so the existing screens work as they are.
 */
final class AssessmentApi
{
    public static function run(Request $request)
    {
        Ctx::boot($request);
        try {
            return (new self())->dispatch();
        } catch (ApiStop $stop) {
            // Anything left uncommitted is rolled back, exactly like the original script ending early.
            if (Ctx::$db->inTransaction()) {
                Ctx::$db->rollBack();
            }
            return $stop->response;
        }
    }

    private function dispatch()
    {
        Ctx::$S['csrf'] ??= bin2hex(random_bytes(24));
        try {
     $db=Ctx::$db;
     $db->exec("SET time_zone = '+00:00'");
     $action=$_GET['action']??'session';$post=$_SERVER['REQUEST_METHOD']==='POST';
     $input=$post?json_decode(file_get_contents('php://input'),true):[];$input=is_array($input)?$input:[];
     if($post && !hash_equals(Ctx::$S['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))fail('Your session expired. Refresh and try again.',403);
     $writes=['login','logout','create','allocate','advance','save','session_end','heartbeat','upload','password','sequence_import','manual_allocate','operations','close_assessment','restart_test','change_group','user_create','user_update','user_delete','enter','enroll'];
     if(in_array($action,$writes,true)&&!$post)fail('Use POST for this action.',405);
     if($action==='session') respond(['staff'=>Ctx::$S['staff']??null,'role'=>!empty(Ctx::$S['staff'])?(query('SELECT role FROM staff WHERE username=?',[Ctx::$S['staff']])->fetchColumn()?:null):null,'csrf'=>Ctx::$S['csrf']]);
     if($action==='login') {
      $ip=$_SERVER['REMOTE_ADDR'];$attempt=query('SELECT * FROM login_attempts WHERE ip=?',[$ip])->fetch();
      if($attempt&&$attempt['failures']>=10&&strtotime($attempt['last_attempt'])>time()-900)fail('Too many attempts. Please try again in 15 minutes.',429);
      $u=query('SELECT * FROM staff WHERE username=?',[(string)($input['username']??'')])->fetch();
      if(!$u||!password_verify((string)($input['password']??''),$u['password_hash'])){query('INSERT INTO login_attempts VALUES (?,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE failures=IF(last_attempt < UTC_TIMESTAMP()-INTERVAL 15 MINUTE,1,failures+1),last_attempt=UTC_TIMESTAMP()',[$ip]);fail('Username or password is incorrect.',401);}
      query('DELETE FROM login_attempts WHERE ip=?',[$ip]);Ctx::$req->session()->regenerate();Ctx::$S['staff']=$u['username'];audit(null,'staff_sign_in');respond(['ok'=>true]);
     }
     if($action==='logout'){Ctx::$req->session()->invalidate();respond(['ok'=>true]);}
     if($action==='enroll'){
                // First scan of a shared link: create the patient. The phone remembers the returned token.
                $entry=(string)($input['entry']??'');if(!in_array($entry,['music','no-music'],true))fail('This link is not valid.',404);
                $ipk=substr(hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??''),(string)file_get_contents(storage_path('app/private/key'))),0,16);
                $perIp=(int)query("SELECT COUNT(*) FROM audit WHERE action='patient_enrolled' AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR AND JSON_UNQUOTE(JSON_EXTRACT(details,'$.ip'))=?",[$ipk])->fetchColumn();
                $all=(int)query("SELECT COUNT(*) FROM audit WHERE action='patient_enrolled' AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR")->fetchColumn();
                if($perIp>=40||$all>=300)fail('Too many new sign-ups right now. Please ask your study representative.',429);
                $group=$entry==='music'?'audio':'usual';$audio=theAudio();
                if($group==='audio'&&!$audio)fail('This session is not ready yet. Please ask your study representative.',503);
                do{$code='YM-'.strtoupper(bin2hex(random_bytes(3)));}while(query('SELECT 1 FROM participants WHERE code=?',[$code])->fetch());
                $token=bin2hex(random_bytes(32));
                query("INSERT INTO participants(code,token_hash,token_cipher,site,study_group,audio_id,consent_confirmed,sleep_opt_in,allocation_reference) VALUES (?,?,?,?,?,?,0,1,'Assigned by the link scanned')",[$code,hash('sha256',$token),seal($token),'Self-enrolled (link)',$group,$group==='audio'?$audio:null]);
                audit((int)$db->lastInsertId(),'patient_enrolled',['link'=>$entry,'ip'=>$ipk]);
                respond(['token'=>$token]);
            }
            if($action==='enter'){
                // Patients reach their screens from one of two shared links (/music, /no-music) plus their study code.
                $key='enter:'.$_SERVER['REMOTE_ADDR'];$attempt=query('SELECT * FROM login_attempts WHERE ip=?',[$key])->fetch();
                if($attempt&&$attempt['failures']>=10&&strtotime($attempt['last_attempt'])>time()-900)fail('Too many attempts. Please try again in 15 minutes.',429);
                $entry=(string)($input['entry']??'');if(!in_array($entry,['music','no-music'],true))fail('This link is not valid.',404);
                $code=strtoupper(trim((string)($input['code']??'')));
                $found=preg_match('/^YM-[A-Z0-9-]{3,20}$/',$code)?query('SELECT * FROM participants WHERE code=?',[$code])->fetch():false;
                if(!$found){query('INSERT INTO login_attempts VALUES (?,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE failures=IF(last_attempt < UTC_TIMESTAMP()-INTERVAL 15 MINUTE,1,failures+1),last_attempt=UTC_TIMESTAMP()',[$key]);fail('We could not find that code. Please check it with your study representative.',404);}
                $want=$entry==='music'?'audio':'usual';
                if($found['study_group']!=='unassigned'&&$found['study_group']!==$want)fail('This code belongs to the other QR code or link. Please use the one your study representative gave you.',409);
                query('DELETE FROM login_attempts WHERE ip=?',[$key]);audit((int)$found['id'],'patient_entered',['link'=>$entry]);
                respond(['token'=>unseal($found['token_cipher'])]);
            }
            if($action==='patient'){
      $p=patient();$forms=json_decode(file_get_contents(base_path('questions.json')),true);
      foreach($forms as &$form)$form['questions']=array_values(array_filter($form['questions'],fn($q)=>!isset($q['group'])||$q['group']===$p['study_group']));unset($form);
      respond(['participant'=>snapshot($p),'forms'=>$forms,'audio_available'=>(bool)$p['audio_id']]);
     }
     if($action==='save') {
      $db->beginTransaction();$p=patient(true);$type=(string)($input['type']??'');
      if($type!==$p['stage']||!in_array($type,['t0','t2','evening','sleep'],true))fail('This assessment is no longer open. Refresh to see your next step.',409);
      $revision=(int)(query('SELECT revision FROM assessments WHERE participant_id=? AND type=?',[$p['id'],$type])->fetchColumn()?:0);
      if(($input['revision']??null)!==$revision)fail('This response was updated in another window. Refresh before continuing.',409);
      $forms=json_decode(file_get_contents(base_path('questions.json')),true);$raw=$input['answers']??[];$rawMissing=$input['missing']??[];if(!is_array($raw)||!is_array($rawMissing))fail('Invalid answers.');$clean=[];$missing=[];
      foreach($forms[$type]['questions'] as $q){if(!visible($q,$clean,$p['study_group'])){$missing[$q['id']]='not_applicable';continue;}$v=$raw[$q['id']]??null;if($v===null||$v===''){if(isset($rawMissing[$q['id']])){if(!in_array($rawMissing[$q['id']],['skipped','declined'],true))fail('Invalid missing-answer status.');$missing[$q['id']]=$rawMissing[$q['id']];}continue;}
       $valid=match($q['type']){'scale'=>is_int($v)&&$v>=0&&$v<=10,'number'=>is_int($v)&&$v>=0&&$v<=$q['max'],'choice'=>is_string($v)&&in_array($v,$q['options'],true),'multi'=>is_array($v)&&count($v)>0&&count(array_diff($v,$q['options']))===0,'text'=>is_string($v)&&mb_strlen($v)<=2000,default=>false};
       if(!$valid)fail('Please check your answer: '.$q['title']);$clean[$q['id']]=$v;
      }
      if(isset($clean['sleep_total_hours'],$clean['sleep_total_minutes'])&&$clean['sleep_total_hours']*60+$clean['sleep_total_minutes']>1440)fail('Total sleep time cannot exceed 24 hours.');
      if(isset($clean['sleep_total_hours'],$clean['sleep_total_minutes']))$clean['sleep_total_duration_minutes']=$clean['sleep_total_hours']*60+$clean['sleep_total_minutes'];
      if(isset($clean['t2_effects']))foreach(['Headache'=>'headache','Dizziness'=>'dizziness','Auditory irritation or discomfort'=>'auditory','Increased anxiety or nervousness'=>'anxiety','Other'=>'other'] as $label=>$suffix)$clean['t2_effect_'.$suffix]=in_array($label,$clean['t2_effects'],true)?1:0;
      $submit=($input['submit']??false)===true;$decline=($input['decline']??false)===true;
      if($submit&&!$decline)foreach($forms[$type]['questions'] as $q)if(visible($q,$clean,$p['study_group'])&&!array_key_exists($q['id'],$clean)&&!isset($missing[$q['id']]))fail('Please answer the remaining questions or choose Finish later.');
      $core=in_array($type,['t0','t2'],true)&&isset($clean[$type.'_anxiety'],$clean[$type.'_relaxation']);
      $hasMissing=count(array_filter($missing,fn($v)=>$v!=='not_applicable'))>0;
      if($decline)foreach($forms[$type]['questions'] as $q)if(visible($q,$clean,$p['study_group'])&&!isset($clean[$q['id']]))$missing[$q['id']]='declined';
      $status=$decline?'declined':($submit&&!$hasMissing?'completed':'partial');
      query('INSERT INTO assessments(participant_id,type,status,answers,missing,core_completed,submitted_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),answers=VALUES(answers),missing=VALUES(missing),core_completed=VALUES(core_completed),revision=revision+1,saved_at=UTC_TIMESTAMP(),submitted_at=VALUES(submitted_at)',[$p['id'],$type,$status,json_encode($clean),json_encode($missing),$core?1:0,($submit||$decline)?gmdate('Y-m-d H:i:s'):null]);
      query('INSERT INTO assessment_history(participant_id,type,revision,answers,missing,status,actor) VALUES (?,?,?,?,?,?,?)',[$p['id'],$type,$revision+1,json_encode($clean),json_encode($missing),$status,'participant']);
      if($submit||$decline){$next=match($type){'t0'=>!$core?'done':($p['study_group']!=='unassigned'?'session':'allocation'),'t2'=>$p['is_test']?'evening':'recovery','evening'=>$p['sleep_opt_in']?($p['is_test']?'sleep':'followup'):'done','sleep'=>'done'};query("UPDATE participants SET stage=?,session_started_at=CASE WHEN ?='session' THEN UTC_TIMESTAMP() ELSE session_started_at END WHERE id=?",[$next,$next,$p['id']]);audit($p['id'],'assessment_'.$status,['type'=>$type,'core_completed'=>$core]);}
      $db->commit();respond(['ok'=>true,'saved_at'=>gmdate('c'),'status'=>$status,'revision'=>$revision+1]);
     }
     if($action==='restart_test'){
      $db->beginTransaction();$p=patient(true);if(!$p['is_test'])fail('Only test walkthroughs can be restarted.',403);
      $rows=query('SELECT * FROM assessments WHERE participant_id=?',[$p['id']])->fetchAll();audit($p['id'],'test_walkthrough_archived',['assessments'=>$rows]);
      query('DELETE FROM assessments WHERE participant_id=?',[$p['id']]);query('DELETE FROM assessment_history WHERE participant_id=?',[$p['id']]);
      query("UPDATE participants SET stage='t0',listening_seconds=0,sleep_listening_seconds=0,session_started_at=NULL,session_ended_at=NULL,session_note=NULL WHERE id=?",[$p['id']]);$db->commit();respond(['ok'=>true]);
     }
     if($action==='heartbeat'){
      $db->beginTransaction();$p=patient(true);$sleep=($input['kind']??'')==='sleep';if($sleep?(!$p['sleep_opt_in']||!$p['sleep_audio_id']||!in_array($p['stage'],['recovery','evening','followup','sleep'],true)):($p['stage']!=='session'||$p['study_group']!=='audio'||!$p['audio_id']))fail('No active listening session.',409);$bk='beat_'.($sleep?'s_':'').$p['id'];
      $n=$input['seconds']??0;if(!is_int($n)||$n<0||$n>15)fail('Invalid listening interval.');
      $last=(int)(Ctx::$S[$bk]??time());$elapsed=max(0,time()-$last);Ctx::$S[$bk]=time();$n=min($n,$elapsed);
      if($sleep)query('UPDATE participants SET sleep_listening_seconds=sleep_listening_seconds+? WHERE id=?',[$n,$p['id']]);else query('UPDATE participants SET listening_seconds=listening_seconds+?,session_started_at=COALESCE(session_started_at,UTC_TIMESTAMP()) WHERE id=?',[$n,$p['id']]);$db->commit();respond(['ok'=>true]);
     }
     if($action==='session_end'){
      $db->beginTransaction();$p=patient(true);if($p['stage']!=='session')fail('This step is already finished.',409);
      query("UPDATE participants SET stage='wait_t2',session_ended_at=UTC_TIMESTAMP(),session_note=? WHERE id=?",[mb_substr((string)($input['reason']??'Participant continued'),0,500),$p['id']]);audit($p['id'],'session_finished');$db->commit();respond(['ok'=>true]);
     }
     if($action==='audio'){
      if(!empty(Ctx::$S['staff'])&&isset($_GET['id']))$id=(int)$_GET['id'];else{$p=patient();if(($_GET['kind']??'')==='sleep'){if(!$p['sleep_opt_in']||!in_array($p['stage'],['recovery','evening','followup','sleep'],true))fail('Sleep audio is not available for this step.',403);$id=$p['sleep_audio_id'];}else{if($p['study_group']!=='audio'||$p['stage']!=='session')fail('Audio is not available for this step.',403);$id=$p['audio_id'];}}
      $a=query('SELECT * FROM audio_files WHERE id=?',[$id])->fetch();if(!$a)fail('Audio has not been added yet.',404);
      $path=storage_path('app/private/audio/').$a['filename'];$size=filesize($path);$start=0;$end=$size-1;$status=200;$hdrs=[];
      if(isset($_SERVER['HTTP_RANGE'])&&preg_match('/bytes=(\d+)-(\d*)/',$_SERVER['HTTP_RANGE'],$m)){$start=(int)$m[1];if($m[2]!=='')$end=min($end,(int)$m[2]);if($start>$end)throw new ApiStop(Ctx::plain('',416));$status=206;$hdrs['Content-Range']="bytes $start-$end/$size";}
      $hdrs['Content-Type']=$a['mime'];$hdrs['Accept-Ranges']='bytes';$hdrs['Content-Length']=(string)($end-$start+1);throw new ApiStop(Ctx::stream(function()use($path,$start,$end){$f=fopen($path,'rb');fseek($f,$start);$remaining=$end-$start+1;while($remaining>0&&!feof($f)){$buf=fread($f,min(65536,$remaining));echo $buf;$remaining-=strlen($buf);}fclose($f);},$status,$hdrs));
     }
     staff();
     $role=query('SELECT role FROM staff WHERE username=?',[Ctx::$S['staff']])->fetchColumn();
     if(!$role){Ctx::$req->session()->invalidate();fail('Please sign in to continue.',401);}
     if($role==='viewer'&&in_array($action,['create','allocate','advance','upload','sequence_import','manual_allocate','change_group','operations','close_assessment'],true))fail('Your role can view study data but not change it.',403);
     if($role!=='admin'&&in_array($action,['upload','sequence_import','users_list','user_create','user_update','user_delete'],true))fail('Only an admin can do this.',403);
     $roles=['admin','representative','viewer'];
     $adminCount=fn()=>(int)query("SELECT COUNT(*) FROM staff WHERE role='admin'")->fetchColumn();
     if($action==='users_list')respond(['users'=>query('SELECT id,username,role,created_at FROM staff ORDER BY id')->fetchAll(),'me'=>Ctx::$S['staff']]);
     if($action==='user_create'){
      $u=strtolower(trim((string)($input['username']??'')));$pw=(string)($input['password']??'');$r=(string)($input['role']??'');
      if(!preg_match('/^[a-z0-9._-]{3,40}$/',$u))fail('Username: 3 to 40 letters, numbers, dots, dashes or underscores.');
      if(strlen($pw)<12)fail('Use at least 12 characters for the password.');
      if(!in_array($r,$roles,true))fail('Choose a role.');
      if(query('SELECT 1 FROM staff WHERE username=?',[$u])->fetch())fail('That username already exists.',409);
      query('INSERT INTO staff(username,password_hash,role) VALUES (?,?,?)',[$u,password_hash($pw,PASSWORD_DEFAULT),$r]);audit(null,'user_created',['username'=>$u,'role'=>$r]);respond(['ok'=>true]);
     }
     if($action==='user_update'){
      $t=query('SELECT * FROM staff WHERE id=?',[(int)($input['id']??0)])->fetch();if(!$t)fail('User not found.',404);
      $r=(string)($input['role']??$t['role']);$pw=(string)($input['password']??'');
      if(!in_array($r,$roles,true))fail('Choose a role.');
      if($pw!==''&&strlen($pw)<12)fail('Use at least 12 characters for the password.');
      if($t['role']==='admin'&&$r!=='admin'&&$adminCount()<=1)fail('Keep at least one admin.',409);
      if($pw!=='')query('UPDATE staff SET role=?,password_hash=? WHERE id=?',[$r,password_hash($pw,PASSWORD_DEFAULT),$t['id']]);else query('UPDATE staff SET role=? WHERE id=?',[$r,$t['id']]);
      audit(null,'user_updated',['username'=>$t['username'],'role'=>$r,'password_changed'=>$pw!=='']);respond(['ok'=>true]);
     }
     if($action==='user_delete'){
      $t=query('SELECT * FROM staff WHERE id=?',[(int)($input['id']??0)])->fetch();if(!$t)fail('User not found.',404);
      if($t['username']===Ctx::$S['staff'])fail('You cannot remove your own account.',409);
      if($t['role']==='admin'&&$adminCount()<=1)fail('Keep at least one admin.',409);
      query('DELETE FROM staff WHERE id=?',[$t['id']]);audit(null,'user_removed',['username'=>$t['username']]);respond(['ok'=>true]);
     }
     if($action==='list')respond(['role'=>$role,'participants'=>array_map(fn($p)=>snapshot($p,true),query('SELECT * FROM participants ORDER BY id DESC')->fetchAll()),'audio'=>query('SELECT id,title,description,created_at FROM audio_files ORDER BY id DESC')->fetchAll(),'forms'=>json_decode(file_get_contents(base_path('questions.json')),true)]);
     if($action==='create'){
      $site=trim((string)($input['site']??''));if(!$site||mb_strlen($site)>100)fail('Enter a site name (up to 100 characters).');if(($input['consent']??false)!==true)fail('Confirm consent before creating a participant.');
      $token=bin2hex(random_bytes(32));$code='YM-'.strtoupper(bin2hex(random_bytes(3)));
      query('INSERT INTO participants(code,token_hash,token_cipher,site,consent_confirmed,sleep_opt_in) VALUES (?,?,?,?,1,?)',[$code,hash('sha256',$token),seal($token),$site,!empty($input['sleep'])?1:0]);audit((int)$db->lastInsertId(),'participant_created',['sleep_opt_in'=>!empty($input['sleep'])]);respond(['ok'=>true,'code'=>$code]);
     }
     if($action==='manual_allocate'){
      $db->beginTransaction();$p=query('SELECT * FROM participants WHERE id=? FOR UPDATE',[(int)($input['id']??0)])->fetch();
      if(!$p||!in_array($p['stage'],['t0','allocation'],true)||$p['study_group']!=='unassigned')fail('Group assignment is already locked.',409);
      if(!$p['consent_confirmed']&&!$p['is_test'])fail('Confirm consent before assignment.',409);
      $group=$input['group']??'';if(!in_array($group,['audio','usual'],true))fail('Choose with music or without music.');
      $audio=theAudio();if($group==='audio'&&!$audio)fail('Upload the study recording in the Audio library first.');
      $next=$p['stage']==='allocation'?'session':'t0';
      query("UPDATE participants SET study_group=?,audio_id=?,stage=?,allocation_reference='Manual staff selection',randomized_at=NULL,session_started_at=CASE WHEN ?='session' THEN UTC_TIMESTAMP() ELSE NULL END WHERE id=?",[$group,$group==='audio'?$audio:null,$next,$next,$p['id']]);
      audit($p['id'],'manual_group_assigned',['group'=>$group,'audio_id'=>$group==='audio'?$audio:null]);$db->commit();respond(['ok'=>true]);
     }
     if($action==='change_group'){
      $db->beginTransaction();$p=query('SELECT * FROM participants WHERE id=? FOR UPDATE',[(int)($input['id']??0)])->fetch();
      if(!$p)fail('Participant not found.',404);
      if($p['study_group']==='unassigned')fail('Choose the first group before changing it.',409);
      $group=$input['group']??'';if(!in_array($group,['audio','usual'],true))fail('Choose with music or without music.');
      if($group===$p['study_group'])fail('The participant is already in that group.',409);
      $reason=trim((string)($input['reason']??''));if(mb_strlen($reason)<3||mb_strlen($reason)>300)fail('Add a short reason for the change.');
      $audio=theAudio();if($group==='audio'&&!$audio)fail('Upload the study recording in the Audio library first.');
      query("UPDATE participants SET study_group=?,audio_id=?,allocation_reference='Manual change by staff',randomized_at=NULL WHERE id=?",[$group,$group==='audio'?$audio:null,$p['id']]);
      audit($p['id'],'group_changed',['from'=>$p['study_group'],'to'=>$group,'stage'=>$p['stage'],'reason'=>$reason]);$db->commit();respond(['ok'=>true]);
     }
     if($action==='allocate'){
      $db->beginTransaction();$p=query('SELECT * FROM participants WHERE id=? FOR UPDATE',[(int)($input['id']??0)])->fetch();if(!$p||$p['stage']!=='allocation')fail('Complete baseline before assigning a group.',409);
      if(!query("SELECT core_completed FROM assessments WHERE participant_id=? AND type='t0'",[$p['id']])->fetchColumn())fail('Both core baseline responses are required before allocation.',409);
      if(!$p['consent_confirmed']&&!$p['is_test'])fail('Document consent before allocation.',409);
      $audio=theAudio();if(!$audio)fail('Upload the study recording in the Audio library first.');
      $allocation=query('SELECT * FROM allocation_sequences WHERE site=? AND participant_id IS NULL ORDER BY position LIMIT 1 FOR UPDATE',[$p['site']])->fetch();
      if(!$allocation)fail('No approved allocation is available for this site. Import the approved sequence first.',409);
      $group=$allocation['study_group'];$ref=$allocation['reference'].' / '.$allocation['position'];
      query('UPDATE allocation_sequences SET participant_id=?,used_at=UTC_TIMESTAMP() WHERE id=?',[$p['id'],$allocation['id']]);
      query("UPDATE participants SET study_group=?,stage='session',audio_id=?,session_started_at=UTC_TIMESTAMP(),randomized_at=UTC_TIMESTAMP(),allocation_reference=? WHERE id=?",[$group,$group==='audio'?$audio:null,$ref,$p['id']]);audit($p['id'],'randomized',['group'=>$group,'sequence_reference'=>$ref]);$db->commit();respond(['ok'=>true]);
     }
     if($action==='sequence_import'){
      $site=trim((string)($input['site']??''));$ref=trim((string)($input['reference']??''));$groups=$input['groups']??[];
      if(!$site||mb_strlen($site)>100||!$ref||mb_strlen($ref)>100||!is_array($groups)||count($groups)<2||count($groups)>10000||count(array_diff($groups,['audio','usual']))||count(array_filter($groups,fn($v)=>$v==='audio'))!==count(array_filter($groups,fn($v)=>$v==='usual')))fail('Supply a site, approved sequence reference, and a balanced 1:1 list of audio/usual allocations.');
      if(($input['approved']??false)!==true)fail('Confirm that this is an approved sequence.');
      $db->beginTransaction();if(query('SELECT id FROM allocation_sequences WHERE site=? LIMIT 1 FOR UPDATE',[$site])->fetch())fail('A sequence already exists for this site. It cannot be replaced through this interface.',409);
      foreach($groups as $i=>$g)query('INSERT INTO allocation_sequences(site,position,study_group,reference) VALUES (?,?,?,?)',[$site,$i+1,$g,$ref]);audit(null,'approved_sequence_imported',['site'=>$site,'reference'=>$ref,'count'=>count($groups)]);$db->commit();respond(['ok'=>true]);
     }
     if($action==='operations'){
      $db->beginTransaction();$p=query('SELECT * FROM participants WHERE id=? FOR UPDATE',[(int)($input['id']??0)])->fetch();if(!$p)fail('Participant not found.',404);
      $old=query('SELECT * FROM operational_records WHERE participant_id=?',[$p['id']])->fetch();$rev=$old?(int)$old['revision']:0;if(($input['revision']??null)!==$rev)fail('This record changed in another window. Refresh before saving.',409);
      $record=$input['record']??[];if(!is_array($record)||strlen(json_encode($record))>16000)fail('Invalid operational record.');
      $allowed=['procedure','consent_version','consent_at','consent_recorded_by','delay_status','delay_minutes','delay_reason','delay_review_owner','medication_context','session_completed','interruption_reason','technical_issue','safety_concern','escalated_to','escalated_at','safety_reference','deviation','deviation_reference','sleep_offered','evening_due','sleep_due','evening_sms_consent','sleep_sms_consent','followup_notes','staff_manageability','staff_interference','staff_feedback'];
      $record=array_intersect_key($record,array_flip($allowed));foreach($record as $v)if(!is_string($v)||mb_strlen($v)>2000)fail('Operational fields must be text up to 2000 characters.');
      if(!in_array($record['delay_status']??'Unknown',['No','Yes','Unknown'],true))fail('Choose a valid delay status.');
      if(($record['delay_status']??'')==='Yes'&&(!(isset($record['delay_minutes']))||!is_numeric($record['delay_minutes'])||floatval($record['delay_minutes'])<0||empty($record['delay_reason'])))fail('Record the delay duration and reason.');
      $sleepAudio=theAudio();
      query('INSERT INTO operational_records(participant_id,record,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE record=VALUES(record),revision=revision+1,updated_by=VALUES(updated_by)',[$p['id'],json_encode($record),Ctx::$S['staff']]);
      query('UPDATE participants SET sleep_audio_id=? WHERE id=?',[$p['sleep_opt_in']&&$sleepAudio?$sleepAudio:null,$p['id']]);audit($p['id'],'operations_updated',['before'=>$old?json_decode($old['record'],true):null,'after'=>$record]);$db->commit();respond(['ok'=>true]);
     }
     if($action==='close_assessment'){
      $db->beginTransaction();$p=query('SELECT * FROM participants WHERE id=? FOR UPDATE',[(int)($input['id']??0)])->fetch();if(!$p||!in_array($p['stage'],['t0','session','wait_t2','t2','evening','sleep'],true))fail('There is no open assessment to close.',409);
      $type=in_array($p['stage'],['session','wait_t2'],true)?'t2':$p['stage'];$reason=trim((string)($input['reason']??''));if(!in_array($reason,['Physician ready to proceed','Clinical instruction','Patient declined','Patient unable to complete','Technical issue','Research workflow issue','Other','Reason unknown'],true))fail('Choose the documented reason.');
      $old=query('SELECT * FROM assessments WHERE participant_id=? AND type=?',[$p['id'],$type])->fetch();$answers=$old?$old['answers']:'{}';$revision=$old?(int)$old['revision']+1:1;$status=$old&&count(json_decode($answers,true))?'partial':($reason==='Patient declined'?'declined':'missed');
      $core=$old?(int)$old['core_completed']:0;query('INSERT INTO assessments(participant_id,type,status,answers,closure_reason,opened_at) VALUES (?,?,?,?,?,NULL) ON DUPLICATE KEY UPDATE status=VALUES(status),closure_reason=VALUES(closure_reason),revision=revision+1,saved_at=UTC_TIMESTAMP()',[$p['id'],$type,$status,$answers,$reason]);
      query('INSERT INTO assessment_history(participant_id,type,revision,answers,missing,status,actor) VALUES (?,?,?,?,?,?,?)',[$p['id'],$type,$revision,$answers,$old['missing']??'{}',$status,Ctx::$S['staff']]);
      $next=match($type){'t0'=>'done','t2'=>'recovery','evening'=>$p['sleep_opt_in']?'followup':'done','sleep'=>'done'};query("UPDATE participants SET stage=?,session_ended_at=CASE WHEN ?='t2' THEN COALESCE(session_ended_at,UTC_TIMESTAMP()) ELSE session_ended_at END WHERE id=?",[$next,$type,$p['id']]);audit($p['id'],'assessment_closed',['type'=>$type,'reason'=>$reason,'status'=>$status]);$db->commit();respond(['ok'=>true]);
     }
     if($action==='advance'){
      $db->beginTransaction();$p=query('SELECT * FROM participants WHERE id=? FOR UPDATE',[(int)($input['id']??0)])->fetch();if(!$p)fail('Participant not found.',404);
      $next=match($p['stage']){'session','wait_t2'=>'t2','recovery'=>'evening','followup'=>$p['sleep_opt_in']?'sleep':'done',default=>null};if(!$next)fail('This stage cannot be opened yet.',409);
      $op=query('SELECT record FROM operational_records WHERE participant_id=?',[$p['id']])->fetchColumn();$op=$op?json_decode($op,true):[];$due=$op[$next==='evening'?'evening_due':'sleep_due']??'';if($due&&!$p['is_test']&&strtotime($due)>time())fail('The scheduled follow-up time has not arrived.',409);query("UPDATE participants SET stage=?,session_ended_at=CASE WHEN ?='t2' THEN COALESCE(session_ended_at,UTC_TIMESTAMP()) ELSE session_ended_at END,session_note=CASE WHEN ?='t2' THEN COALESCE(session_note,'Opened by staff') ELSE session_note END WHERE id=?",[$next,$next,$next,$p['id']]);audit($p['id'],'stage_opened',['stage'=>$next]);$db->commit();respond(['ok'=>true]);
     }
     if($action==='upload'){
      $file=$_FILES['audio']??null;if(!$file||$file['error']!==UPLOAD_ERR_OK)fail('Choose an audio file up to 100 MB.');if($file['size']>100*1024*1024)fail('Audio must be under 100 MB.');
      $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$types=['audio/mpeg'=>'mp3','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/ogg'=>'ogg','audio/mp4'=>'m4a','video/mp4'=>'m4a'];if(!isset($types[$mime]))fail('Use an MP3, WAV, OGG, or M4A audio file.');
      $title=trim($_POST['title']??'');if(!$title||mb_strlen($title)>120)fail('Give this audio a title (up to 120 characters).');$name=bin2hex(random_bytes(16)).'.'.$types[$mime];if(!move_uploaded_file($file['tmp_name'],storage_path('app/private/audio/').$name))fail('Audio could not be stored.',500);
      $desc=mb_substr(trim((string)($_POST['description']??'')),0,300);$old=query('SELECT id,filename FROM audio_files ORDER BY id LIMIT 1')->fetch();if($old){query('UPDATE audio_files SET title=?,description=?,filename=?,mime=? WHERE id=?',[$title,$desc?:null,$name,$mime,$old['id']]);@unlink(storage_path('app/private/audio/').$old['filename']);}else query('INSERT INTO audio_files(title,description,filename,mime) VALUES (?,?,?,?)',[$title,$desc?:null,$name,$mime]);audit(null,'audio_uploaded',['title'=>$title,'replaced'=>(bool)$old]);respond(['ok'=>true]);
     }
     if($action==='password'){$old=(string)($input['old']??'');$new=(string)($input['new']??'');$hash=query('SELECT password_hash FROM staff WHERE username=?',[Ctx::$S['staff']])->fetchColumn();if(!password_verify($old,$hash))fail('Current password is incorrect.');if(strlen($new)<12)fail('Use at least 12 characters.');query('UPDATE staff SET password_hash=? WHERE username=?',[password_hash($new,PASSWORD_DEFAULT),Ctx::$S['staff']]);Ctx::$req->session()->regenerate();respond(['ok'=>true]);}
     if($action==='export'){
      $out=fopen('php://temp','r+');fwrite($out,"\xEF\xBB\xBF");
      fputcsv($out,['participant_id','site','group','stage','is_test','consent_confirmed','sleep_opt_in','participant_created_utc','allocation_reference','randomized_utc','audio_id','audio_title','session_started_utc','session_ended_utc','listening_seconds','sleep_listening_seconds','session_note','assessment','status','opened_utc','saved_utc','submitted_utc','revision','core_completed','entry_method','instrument_version','closure_reason','question','answer','answer_status']);
      $rows=query('SELECT p.code,p.site,p.study_group,p.stage,p.is_test,p.consent_confirmed,p.sleep_opt_in,p.created_at,p.allocation_reference,p.randomized_at,p.audio_id,f.title,p.session_started_at,p.session_ended_at,p.listening_seconds,p.sleep_listening_seconds,p.session_note,a.type,a.status,a.opened_at,a.saved_at,a.submitted_at,a.revision,a.core_completed,a.entry_method,a.instrument_version,a.closure_reason,a.answers,a.missing FROM participants p LEFT JOIN assessments a ON a.participant_id=p.id LEFT JOIN audio_files f ON f.id=p.audio_id ORDER BY p.id,a.id')->fetchAll();
      foreach($rows as $r){$answers=json_decode($r['answers']??'{}',true)?:[];$missing=json_decode($r['missing']??'{}',true)?:[];unset($r['answers'],$r['missing']);$keys=array_unique(array_merge(array_keys($answers),array_keys($missing)));if(!$keys)$keys=[''];foreach($keys as $key){$val=$answers[$key]??'';$line=array_merge(array_values($r),[$key,is_array($val)?implode('; ',$val):$val,array_key_exists($key,$answers)?'answered':($missing[$key]??'not_recorded')]);$line=array_map(fn($v)=>is_string($v)&&preg_match('/^[=+@\-\t\r\n]/',$v)?"'".$v:$v,$line);fputcsv($out,$line);}}
      audit(null,'csv_export');rewind($out);$csv=stream_get_contents($out);fclose($out);throw new ApiStop(Ctx::download($csv,'youmind-assessments-'.gmdate('Y-m-d').'.csv'));
     }
     if($action==='export_operations'){
      $o=fopen('php://temp','r+');fwrite($o,"\xEF\xBB\xBF");fputcsv($o,['participant_id','is_test','randomized_utc','allocation_reference','field','value','updated_by','updated_utc']);
      foreach(query('SELECT p.code,p.is_test,p.randomized_at,p.allocation_reference,o.record,o.updated_by,o.updated_at FROM participants p LEFT JOIN operational_records o ON p.id=o.participant_id')->fetchAll() as $r){$values=json_decode($r['record']??'{}',true)?:[''=>''];foreach($values as $k=>$v){$cells=[$r['code'],$r['is_test'],$r['randomized_at'],$r['allocation_reference'],$k,$v,$r['updated_by'],$r['updated_at']];fputcsv($o,array_map(fn($v)=>is_string($v)&&preg_match('/^[=+@\-\t\r\n]/',$v)?"'".$v:$v,$cells));}}rewind($o);$csv=stream_get_contents($o);fclose($o);throw new ApiStop(Ctx::download($csv,'youmind-operations.csv'));
     }
     if($action==='audit')respond(query('SELECT actor,action,details,created_at FROM audit WHERE participant_id=? ORDER BY id DESC LIMIT 100',[(int)($_GET['id']??0)])->fetchAll());
     fail('Not found.',404);
    
        } catch (ApiStop $stop) {
            throw $stop;
        } catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();error_log($e->__toString());fail('The server could not complete this request. Your last confirmed save is safe. Please retry.',500);}
        return null;
    }
}
