<?php
require_once 'Catalog.php';
class User extends Catalog {
    public $min_level=0;
    public $SignIn=['login'=>'^[a-zA-Z_0-9]*$','pass'=>'^[a-zA-Z_0-9]*$','mode'=>'string'];
    public function SignIn($login,$pass,$mode=''){
	if( $login && $pass ){
	    //don't allow empty pass
	    $pass_hash = md5($pass);
	    $user_data = $this->get_row("SELECT * FROM user_list WHERE user_login='$login' AND user_pass='$pass_hash'");
	    if ($user_data && $user_data->user_id) {
		$this->initLoggedUser($user_data);
		header("HTTP/1.1 200 OK");
		if( $mode==='get_user_data' ){
		    return $this->getUserData();
		}
		return true;
	    }
	}
	header("HTTP/1.1 401 Unauthorized");
	return false;
    }
    
    //public $SignByPhone=['user_phone'=>'^[\d]*','user_phone_pass'=>'int'];
    public function sendPassword($user_phone){
        $new_user_pass=$this->generatePassword();
        $user_data = $this->get_row("SELECT user_id,user_level,user_login FROM user_list WHERE user_phone LIKE '%{$user_phone}'");
        if( !$user_data ){
            $user_data=$this->userRegister($user_phone);
        }
        if( $user_data->user_level<1 || !$user_data->user_id ){
            return false;
        }
        if( $this->userInformBySms($user_phone,$user_data->user_login,$new_user_pass) ){
            $this->query("UPDATE user_list SET user_pass=MD5('$new_user_pass') WHERE user_id='$user_data->user_id'");
            return true;
        }
        return false;
    }
    private function userInformBySms($user_phone,$user_login,$new_user_pass){
        $data['user_new_pass']=$new_user_pass;
        $data['user_login']=$user_login;
        $message=$this->load->view('dialog/sms_user_register',$data,true);
        
        $initial_user_level=$this->Hub->svar('user_level');
        $this->Hub->svar('user_level',1);//elevate user level for accessing sms credentials
        $this->Hub->load_model("Utils");
        $this->Hub->load_model("Company");
        $this->Hub->Company->selectActiveCompany(1);// not always right!!!
        $ok=$this->Utils->sendSms($user_phone,$message);
        $this->Hub->svar('user_level',$initial_user_level);
        return $ok;
    }
    private function userRegister($user_phone){
        $client_data = $this->get_row("SELECT company_id,company_name,company_person,path FROM companies_list JOIN companies_tree USING(branch_id) WHERE company_mobile LIKE '%{$user_phone}%'");
        $user_data=new stdClass;
        $user_data->user_id=0;
        $user_data->user_level=0;
        if( $client_data ){
            $sql="INSERT INTO
                user_list
            SET
                user_login='{$user_phone}',
                user_phone='{$user_phone}',
                user_level=1,
                user_assigned_path='{$client_data->path}',
                first_name='{$client_data->company_name}',
                user_sign='{$client_data->company_person}',
                user_permissions='nocommit'
                ";
            $this->query($sql);
            $user_data->user_id=$this->db->insert_id();
            $user_data->user_level=1;
        }
        return $user_data;
    }
    private function generatePassword(){
        $alphabet = 'abcdefghijklmnopqrstuvwxyz1234567890';//ABCDEFGHIJKLMNOPQRSTUVWXYZ
        $password = array(); 
        $alpha_length = strlen($alphabet) - 1; 
        for ($i = 0; $i < 6; $i++) 
        {
            $n = rand(0, $alpha_length);
            $password[] = $alphabet[$n];
        }
        return implode($password);
    }
    
    private function initLoggedUser($user_data){
	$this->Hub->svar('user_id', $user_data->user_id);
	$this->Hub->svar('user_level', $user_data->user_level);
	$this->Hub->svar('user_level_name', $this->Hub->level_names[$user_data->user_level]);
	$this->Hub->svar('user_login', $user_data->user_login);
	$this->Hub->svar('user_sign', $user_data->user_sign);
	$this->Hub->svar('user_position', $user_data->user_position);
	$this->Hub->svar('user_assigned_stat',$user_data->user_assigned_stat);
	$this->Hub->svar('user_assigned_path',$user_data->user_assigned_path);
        $this->Hub->svar('user',$user_data);

	$Company=$this->Hub->load_model("Company");
	if( $user_data->company_id ){
	    $Company->selectActiveCompany($user_data->company_id);
	} else {
	    $Company->switchActiveCompany();
	}
	$PluginManager=$this->Hub->load_model("PluginManager");
	$PluginManager->pluginInitTriggers();
    }
    public $SignOut=[];
    public function SignOut(){
        $_SESSION = array();
	return true;
    }
    public $getUserData=[];
    public function getUserData(){
	return [
	    'user_id'=>$this->Hub->svar('user_id'),
	    'user_login'=>$this->Hub->svar('user_login'),
	    'user_level'=>$this->Hub->svar('user_level'),
	    'user_level_name'=>$this->Hub->svar('user_level_name'),
	    'acomp'=>$this->Hub->svar('acomp'),
	    'pcomp'=>$this->Hub->svar('pcomp'),
	    'module_list'=>$this->getModuleList()
	];
    }
    private function getModuleList(){
	$mods=json_decode(file_get_contents('application/config/modules.json',true));//not very reliable way to check, modules can be loaded anyway by hand
	$alowed=array();
	foreach( $mods as $mod ){
	    if( $this->Hub->svar('user_level')>=$mod->level ){// && strpos(BAY_ACTIVE_MODULES, "/{$mod->name}/")!==false 
		$alowed[]=$mod;
	    }
	}
	return $alowed;
    }
    public $userFetch=[];
    public function userFetch(){
	$user_id = $this->Hub->svar('user_id');
        $sql="SELECT
		user_id,user_login,user_level,user_sign,user_position,user_phone,
		first_name,middle_name,last_name,nick,
		id_type,id_serial,id_number,id_given_by,id_date,
		user_assigned_path,
		CONCAT(last_name,' ',first_name,' ',middle_name) AS full_name 
	    FROM user_list
		WHERE user_id='$user_id'";
        return $this->get_row($sql);
    }
    public $listFetch=[];
    public function listFetch(){
	$user_id = $this->Hub->svar('user_id');
        $where = ($this->Hub->svar('user_level') < 4) ? "WHERE user_id='$user_id'" : "";
        $sql="SELECT
		user_id,user_login,user_level,user_sign,user_position,user_phone,
		first_name,middle_name,last_name,nick,
		id_type,id_serial,id_number,id_given_by,id_date,
		user_assigned_path,user_permissions,
		CONCAT(last_name,' ',first_name,' ',middle_name) AS full_name 
	    FROM user_list
		$where 
	    ORDER BY user_id<>'$user_id', user_level DESC";
        return $this->get_list($sql);
    }
    public $save=[];
    public function save(){
	$fields=[];
	$user_id=$this->request('user_id','int');
	$current_level=$this->Hub->svar('user_level');
	if( $current_level>=1 && $this->Hub->svar('user_id')==$user_id || $current_level>=4){
	    $fields['user_login']=$this->request('user_login','^[a-zA-Z_0-9]*$');
	    $new_pass=$this->request('new_pass','^[a-zA-Z_0-9]*$',false);
	    if( $new_pass ){
		$fields['user_pass']=md5($new_pass);
	    }
	}
	if( $current_level>=3 ){
	    $fields['user_sign']=$this->request('user_sign');
	    $fields['user_position']=$this->request('user_position');	    
	    $fields['first_name']=$this->request('first_name');
	    $fields['middle_name']=$this->request('middle_name');	    
	    $fields['last_name']=$this->request('last_name');
            $fields['nick']=mb_substr($fields['last_name'],0,1).mb_substr($fields['first_name'],0,1).mb_substr($fields['middle_name'],0,1);
	    $fields['user_phone']=$this->request('user_phone');
	    $fields['id_type']=$this->request('id_type');
	    $fields['id_serial']=$this->request('id_serial');	    
	    $fields['id_number']=$this->request('id_number');
	    $fields['id_given_by']=$this->request('id_given_by');	    
	    $fields['id_date']=$this->request('id_date');	    
	}
	if( $current_level>=4 ){
	    $fields['user_level']=$this->request('user_level','int');
	    $fields['user_assigned_path']=$this->request('user_assigned_path');
	    $fields['user_permissions']=$this->request('user_permissions');
	    
	    $admin=$this->adminLastCheck($user_id);
	    if( $admin==='last' && $fields['user_level']<4 ){
		return 'LAST_ADMIN';
	    }
	}
	if( $user_id===0 ){
	    return $this->create("user_list", $fields);
	} else {
	    return $this->update("user_list", $fields,['user_id'=>$user_id]);
	}
    }
    public $remove=['int'];
    public function remove( $user_id ){
	$this->Hub->set_level(4);
	$this->check($user_id,'int');
	$admin=$this->adminLastCheck($user_id);
	if( $admin==='last' ){
	    return 'LAST_ADMIN';
	}
	return $this->delete("user_list", ['user_id'=>$user_id]);
    }
    private function adminLastCheck($user_id){
	return $this->get_value("SELECT IF(user_level=4 AND (SELECT COUNT(*)=1 FROM user_list WHERE user_level=4),'last','not_last') FROM user_list WHERE user_id='$user_id'");
    }
}
