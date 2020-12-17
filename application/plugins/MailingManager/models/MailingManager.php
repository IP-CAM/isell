<?php
/* Group Name: Работа с клиентами
 * User Level: 2
 * Plugin Name: Массовые рассылки
 * Plugin URI: http://isellsoft.com
 * Version: 1.0
 * Description: Массовые рассылки
 * Author: baycik 2020
 * Author URI: http://isellsoft.com
 */


class MailingManager extends Catalog {
    public $settings = [];

    public function index(){
        $this->Hub->set_level(3);
        $this->load->view('mailing_manager.html');
    }

     public function install(){
        $this->Hub->set_level(4);
	$install_file=__DIR__."/../install/install.sql";
	$this->load->model('Maintain');
	return $this->Maintain->backupImportExecute($install_file);
    }

    public function uninstall(){
        $this->Hub->set_level(4);
	$uninstall_file=__DIR__."/../install/uninstall.sql";
	$this->load->model('Maintain');
	return $this->Maintain->backupImportExecute($uninstall_file);
    }

    public function activate(){
        $this->Hub->set_level(4);
    }

    public function deactivate(){
        $this->Hub->set_level(4);
    }

    public function init(){
        $this->pluginSettingsLoad();
        if(!$this->settings){
            $this->settings = [
                'event_id' => false,
                'reciever_list' => new stdClass(),
                'manual_reciever_list' => new stdClass()
            ];
            $this->pluginSettingsFlush();
        }
    }

    private function pluginSettingsFlush() {
        $settings=$this->settings;
        $plugin_data=$this->plugin_data;
        $this->pluginSettingsLoad();
        $plugin_data=(object) array_merge((array) $this->plugin_data, (array) $plugin_data);
        $encoded_settings = json_encode($settings, JSON_UNESCAPED_UNICODE );
        $encoded_data =     json_encode($plugin_data, JSON_UNESCAPED_UNICODE );
        $this->settings=    $settings;
        $this->plugin_data= $plugin_data;
        $sql = "
            UPDATE
                plugin_list
            SET
                plugin_settings = '$encoded_settings',
                plugin_json_data = '$encoded_data'
            WHERE plugin_system_name = 'MailingManager'
            ";
        $this->query($sql);
    }

    private function pluginSettingsLoad() {
        $sql = "
            SELECT
                plugin_settings,
                plugin_json_data
            FROM
                plugin_list
            WHERE plugin_system_name = 'MailingManager'
            ";
        $row = $this->get_row($sql);
        $this->settings=json_decode( str_replace("\n", '\n', $row->plugin_settings) );
        $this->plugin_data=json_decode( str_replace("\n", '\n', $row->plugin_json_data) );
    }


    public function settingsGet(){
        return [
            'settings' => $this->settings,
            'staff_list' => $this->Hub->load_model("Pref")->getStaffList()
        ];
    }

    public function settingsUpdate(array $settings){
        $this->settings = $settings;
        $this->pluginSettingsFlush();
        return true;
    }
    /*
     * Message CRUD functions
     */
    public function messageCreate( array $message ){
        $user_id=$this->Hub->svar('user_id');
        if(empty($message)){
            return;
        }
        $message_record=[
            'message_handler'=>$message['message_handler'],
            'message_batch_label'=>$message['message_batch_label']??($message['message_handler'].$message['message_reason']),
            'message_status'=>'created',
            'message_reason'=>$message['message_reason'],
            'message_note'=>$message['message_note'],
            'message_recievers'=>$message['message_recievers'],
            'message_subject'=>$message['message_subject'],
            'message_body'=>$message['message_body'],
            'created_by'=>$user_id,
            'modified_by'=>$user_id
        ];
        return $this->create('plugin_message_list',$message_record);
    }
    public function messageUpdate( int $message_id, array $message ){
        $user_id=$this->Hub->svar('user_id');
        $message_record=[
            'message_handler'=>$message['message_handler'],
            'message_batch_label'=>$message['message_batch_label'],
            'message_status'=>'created',
            'message_reason'=>$message['message_reason'],
            'message_note'=>$message['message_note'],
            'message_recievers'=>$message['message_recievers'],
            'message_subject'=>$message['message_subject'],
            'message_body'=>$message['message_body'],
            'created_by'=>$user_id,
            'modified_by'=>$user_id
        ];
        return $this->update('plugin_message_list',$message_record,['message_id'=>$message_id]);
    }

    private function messageChangeStatus(int $message_id, string $status, string $old_status = ""){
        $where = "";
        if(!empty($old_status)){
            $where = " AND message_status = '$old_status'";
        }
         $sql="
            UPDATE
                plugin_message_list
            SET 
                message_status = '$status'
            WHERE
                message_id = '$message_id' $where
            ";
        return $this->query($sql);
    }

    public function messageDelete( $message_id ){
        return $this->delete('plugin_message_list',['message_id'=>$message_id]);
    }

    public function messageSend( $message_id ){
        $this->settings = $this->settingsGet();
        $this->messageChangeStatus($message_id, 'processing');
        $this->settings['event_id'] = $this->mailingCreate();
        $this->pluginSettingsFlush();
    }
    
    public function messageCancelSending( $message_id ){
        $this->messageChangeStatus($message_id, 'created', 'processing');
    }
    
    private function messageRenderTpl( string $message_template, $context ){
        $_self=$this;
        $message_template=preg_replace_callback('/{{(\w+)\.?(\w+)?(\([^\)]+\))?}}/',function($matches) use ($_self,$context){
            if($matches[2]??false){
                try{
                    $Model=$this->Hub->load_model($matches[1]);
                    if(method_exists($Model, $matches[2])){
                        /*
                         * Try to execute widget function if fail abort message
                         */
                        $result=$Model->{$matches[2]}($context);
                        if( $result===false ){
                            $this->message_composing_aborted=true;
                        }
                        return $result;
                    }
                    return '???';
                } catch(Exception $e){
                    return '???';
                }
                return '???';
            }
            if($matches[1]??false){
                return $context->{$matches[1]}??'?'.$matches[1];
            }
            return '???';
        },$message_template);
        return $message_template;
    }


    public function messageGet( int $message_id ){
        $sql="
            SELECT
                message_batch_label,
                message_handler,
                message_reason,
                message_note,
                message_recievers,
                message_subject,
                message_body
            FROM
                plugin_message_list
            WHERE
                message_id = $message_id
            ";
        return $this->get_row($sql);
    }

    private function messageListFilterGet( $filter ){
        if( empty($filter) ){
            return '1';
        }
        $signature="CONCAT(message_handler,' ',message_reason,' ',message_note,' ',message_recievers,' ',message_subject)";
        $parts=explode(" ",trim($filter));
        $having=" $signature LIKE '%".implode("%' OR $signature LIKE '%",$parts)."%'";
        return $having;
    }

    public function messageListGet( string $filter='', string $filter_handler='', string $filter_reason='', string $filter_date='' ){
        $where = $this->messageListFilterGet( $filter );
        $msg_list_msg="
            SELECT
                *,
                CONCAT(message_handler,' ',message_reason,' ',message_note,' ',message_recievers,' ',message_subject) signature
            FROM
                plugin_message_list
            WHERE
                message_handler LIKE '%$filter_handler%'
                AND message_reason LIKE '%$filter_reason%'
                AND created_at LIKE '%$filter_date%'
                AND $where
            ";
        return $this->get_list($msg_list_msg);
    }

    /*
     * Message batches CRUD functions
     */
    public function messageBatchCreate( array $message_batch ){
        $mesages_created=false;
        $context = (object)[];
        $batch_label = md5($message_batch['handler'].$message_batch['subject'].date('Y-m-d H:i:s'));
        foreach($message_batch['manual_reciever_list'] as $contact){
            $contact_type = $this->messageDefineContactType($contact);
            if($contact_type == ''){
                continue;
            }
            $context = $this->recieverGetByContact($contact, $contact_type);
            if(empty($context->{"company_".$contact_type})){
                $context->{"company_".$contact_type} = $contact;
            } 
            $message = $this->messageBatchComposeMessage($batch_label, $message_batch, $context);
            $context->company_name = $contact;
            $this->messageCreate($message);
            $mesages_created=true;
        }
        $batch_context = $this->messageBatchContextGet( $message_batch['reciever_list_id'] );
        foreach($batch_context as $context){
            if( empty($context->company_email) && empty($context->company_mobile) ){
                continue;
            }
            $this->message_composing_aborted=false;
            $message = $this->messageBatchComposeMessage($batch_label, $message_batch, $context);
            if($this->message_composing_aborted){
                continue;
            }
            $this->messageCreate($message);
            $mesages_created=true;
        }
        return $mesages_created;
    }
    
    
    
    public function test(){
        $message_batch=[
            'reciever_list_id'=>1608023133866,
            'handler'=>'email',
            'subject'=>'Hello',
            'body'=>'Уважаемый {{DebtManager.DebtCalendar(Календарь платежей)}} {{company_name}}',
            'manual_reciever_list'=>[]
        ];
        $this->messageBatchCreate( $message_batch );
    }
    
    
    
    public function messageBatchComposeMessage(string $batch_label, array $message_batch, stdClass $context ){
        $message = [];
        $validated_contact = $this->messageContactValidate($message_batch['handler'], $context);
        if(!$validated_contact){
            return [];
        }
        $message['message_batch_label'] = $batch_label;
        $message['message_recievers'] = implode('|',$validated_contact);
        $message['message_handler'] = $message_batch['handler'];
        $message['message_reason'] = $message_batch['subject'];
        $message['message_subject'] = $this->messageRenderTpl($message_batch['subject'],$context);;
        $message['message_note'] = '';
        $message['message_body'] = $this->messageRenderTpl($message_batch['body'],$context);
        return $message;
    }
    private function messageBatchContextGet( string $reciever_list_id ){
        $active_company_id=$this->Hub->acomp('company_id');
        $where = $this->recieverListFilterGet($reciever_list_id);
        $sql="
            SELECT
                $active_company_id active_company_id,
                cl.company_id,
                label,
                path,
                deferment,
                company_name,
                company_person,
                company_director,
                company_address,
                company_email,
                company_mobile,
                user_sign manager_sign,
                user_phone manager_phone,
                user_position manager_position,
                DATE_FORMAT(NOW(),'d%.m%.Y%') date_today
            FROM
                companies_list cl
                    JOIN
                companies_tree ct USING(branch_id)
                    LEFT JOIN
                user_list ON manager_id=user_id AND user_is_staff=1
            WHERE $where
            ORDER BY label ASC";
        return $this->get_list($sql);
    }

    public function messageBatchDelete( string $message_batch_label ){
        return $this->delete("plugin_message_list",['message_batch_label'=>$message_batch_label]);
    }
    
    public function messageBatchSend( string $message_batch_label ){
        $this->settings = $this->settingsGet();
        $this->messageBatchChangeStatus($message_batch_label, 'processing');
        $this->settings['event_id'] = $this->mailingCreate();
        $this->pluginSettingsFlush();
    }

    public function messageBatchCancelSending( string $message_batch_label ){
        $this->messageBatchChangeStatus($message_batch_label, 'created', 'processing');
    }
    
    private function messageBatchRecieversGet( $message ){
        $reciever_list = [];
        if((int) $message['message_reciever_list']){
            $reciever_list += array_merge($reciever_list,$this->messageBatchRecieverListGet($message['message_reciever_list']));
        }
        if(!empty($message['message_recievers'])){
            $custom_recievers = explode(',', $message['message_recievers']);
            foreach($custom_recievers as $custom_reciever){
                $custom_reciever_object = new StdClass();
                $custom_reciever_object->{$message['message_handler']} = $custom_reciever;
                $reciever_list[] = $custom_reciever_object;
            }
        }
        return $reciever_list;
    }

    public function messageBatchListGet( string $filter=null ){
        $where = $this->messageListFilterGet( $filter );
        $msg_list_msg="
            SELECT
                message_batch_label,
                message_handler,
                message_reason,
                created_at AS group_created_at,
                created_at,
                COUNT(*) message_count,
                GROUP_CONCAT(DISTINCT message_status) AS message_batch_statuses
            FROM
                plugin_message_list
            WHERE
                $where
            GROUP BY message_batch_label
            ORDER BY created_at DESC,message_handler
            LIMIT 20
            ";
        return $this->get_list($msg_list_msg);
    }

    private function messageBatchChangeStatus(string $message_batch_label, string $status, string $old_status = ""){
        $where = "";
        if(!empty($old_status)){
            $where = " AND message_status = '$old_status'";
        }
         $sql="
            UPDATE
                plugin_message_list
            SET 
                message_status = '$status'
            WHERE
                message_batch_label = '$message_batch_label' $where
            ";
        return $this->query($sql);
    }


    private function messageDefineContactType($contact){
        $contact_type = '';
        if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            $contact_type = 'email';
        }
        if (preg_match("/^((8|\+7)[\- ]?)?(\(?\d{3}\)?[\- ]?)?[\d\- ]{7,10}$/", $contact)) {
            $contact_type = 'mobile';
        }
        return $contact_type;
    }

    private function messageContactValidate($handler, $context){
        if($handler == 'auto'){
            $contacts_array = $this->messageContactValidateEmail($context->company_email);
            if(!$contacts_array){
                $contacts_array = $this->messageContactValidateMobile($context->company_mobile);
            }
        }
        if($handler == 'email'){
            $contacts_array = $this->messageContactValidateEmail($context->company_email);
        }
        if($handler == 'sms'){
            $contacts_array = $this->messageContactValidateMobile($context->company_mobile);
        }
        return $contacts_array;
    }

    private function messageContactValidateEmail($contacts_string){
        if(!empty($contacts_string)){
            preg_match_all("/[a-zA-Z0-9\.-_]+@[a-zA-Z0-9-_]+\.+[a-zA-Z0-9]*/", $contacts_string, $matches);
            if(!empty($matches[0])){
                $email_list = $matches[0];
                return $email_list;
            }
        }
        return false;
    }

    private function messageContactValidateMobile($contacts_string){
        if(!empty($contacts_string)){
            preg_match_all("/[8\+7][\- ]?\(?\d{3}\)?[\- ]??[\d\- ]{7,10}/", $contacts_string, $matches);
            if(!empty($matches[0])){
                foreach($matches[0] as &$mobile){
                    $mobile = str_replace([' ',';',',','(',')','-'], "", $mobile);
                }
                $email_list = $matches[0];
                return $email_list;
            }
        }
        return false;
    }


    private function recieverListFilterGet( string $reciever_list_id ){
        $this->Hub->set_level(2);
        $settings = $this->settingsGet();
        if( empty($this->settings->reciever_list[$reciever_list_id]) ){
            return 0;
        }
        $reciever_list_settings=$this->settings->reciever_list[$reciever_list_id];
        $assigned_path=  $this->Hub->svar('user_assigned_path');
        $user_level=     $this->Hub->svar('user_level');
        $or_case=[];
        $and_case=[];
        if( $assigned_path ){
            $and_case[]=" path LIKE '%".str_replace(",", "%' OR path LIKE '%", $assigned_path)."%'";
        }
        if( !empty($reciever_list_settings['subject_path_include']) ){
            $or_case[]=" path LIKE '%".str_replace(",", "%' OR path LIKE '%", $reciever_list_settings['subject_path_include'])."%'";
        }
        if( !empty($reciever_list_settings['subject_path_exclude']) ){
            $and_case[]=" path NOT LIKE '%".str_replace(",", "%' AND path NOT LIKE '%", $reciever_list_settings['subject_path_exclude'])."%'";
        }
        if( $reciever_list_settings['subject_manager_include']){
            $or_case[]=" manager_id IN (".implode(',',$reciever_list_settings['subject_manager_include']).")";
        }
        if( $reciever_list_settings['subject_manager_exclude']){
            $and_case[]=" manager_id NOT IN (".implode(',', $reciever_list_settings['subject_manager_exclude']).")";
        }
        $where="";
        if( count($or_case) ){
            $where="(".implode(' OR ',$or_case).")";
        }
        if( count($and_case) ){
            if( count($or_case) ){
                $where.=" AND ";
            }
            $where.=implode(' AND ', $and_case);
        }
        return $where?$where." AND level<= $user_level":0;
    }


    public function recieverListFetch(string $reciever_list_id, int $offset=0,int $limit=30,string $sortby='label',string $sortdir='ASC',array $filter){
        $this->Hub->set_level(3);
        $having=$this->makeFilter($filter);
        $where=$this->recieverListFilterGet($reciever_list_id);
        $sql="
            SELECT
                label,
                company_name,
                path
            FROM
                companies_list
                    JOIN
                companies_tree USING(branch_id)
            WHERE $where
            HAVING $having
            ORDER BY $sortby $sortdir
	    LIMIT $limit OFFSET $offset";
        return $this->get_list($sql);
    }

    public function recieverGetByContact(string $contact, string $contact_type){
        $this->Hub->set_level(3);
        $sql="
            SELECT * FROM(
                SELECT
                    label,
                    path,
                    company_name,
                    company_person,
                    company_director,
                    company_address,
                    company_web,
                    company_bank_name,
                    company_email,
                    company_mobile
                FROM
                    companies_list
                        JOIN
                    companies_tree USING(branch_id)
                WHERE
                    company_".$contact_type." = '$contact'
                UNION ALL
                SELECT 
                    '' as label,
                    '' as path,
                    '' as company_name,
                    '' as company_person,
                    '' as company_director,
                    '' as company_address,
                    '' as company_web,
                    '' as company_bank_name,
                    '' as company_email,
                    '' as company_mobile
                FROM
                    companies_list
                LIMIT 1   )t
            LIMIT 1        
            ";
        return $this->get_row($sql);
    }


    public function mailingCreate(){
        $Events=$this->Hub->load_model('Events');
        if(!empty($Events->eventGet($this->settings['event_id']))){
            return $Events->eventGet($this->settings['event_id'])->event_id;
        } else {
            $this->settings['event_id'] = false;
        }
        $program = [
            'commands' => [[
                "model" => "MailingManager",
                "method" => "mailingBegin",
                "arguments" => "1",
                "async" => 1,
                "disabled" => 0
            ]]
        ];
        $doc_id='';
        $event_date= date( "Y-m-d H:i:s", strtotime(date("Y-m-d H:i:s")."+1 minute"));
        $event_priority='3medium';
        $event_name='Рассылка';
        $event_label='-TASK-';
        $event_target='0';
        $event_place='';
        $event_note='';
        $event_descr='Рассылка сообщений';
        $event_program = json_encode($program);
        $event_repeat='0 0:1';
        $event_status='pending';
        $event_liable_user_id='';
        $event_is_private = '1';
        $event_id = $Events->eventSave($this->settings['event_id'],
                $doc_id,
                $event_date,
                $event_priority,
                $event_name,
                $event_label,
                $event_target,
                $event_place,
                $event_note,
                $event_descr,
                $event_program,
                $event_repeat,
                $event_status,
                $event_liable_user_id,
                $event_is_private
                );
        return $event_id;
    }


    public function mailingBegin(){
        $message_list = $this->mailingGetProcessingMessages();
        foreach($message_list as $index=> $message){
            if($index > 4){
                return false;
            }
            $handler_name = ucfirst($message->message_handler).'Handler';
            require_once APPPATH.'/plugins/MailingManager/handlers/'.$handler_name.'.php';
            ${$handler_name} = new $handler_name;
            $message->message_recievers = str_replace('|', ',', $message->message_recievers);
            $ok = ${$handler_name}->send($message->message_recievers, $message);
            if($ok){
                $this->messageChangeStatus($message->message_id, 'done', 'processing');
            }
        }
        return $this->mailingFinish();;
    }


    public function mailingFinish(){
        $this->settings = $this->settingsGet();
        $Events=$this->Hub->load_model('Events');
        $Events->eventDelete($this->settings['event_id']);
        $this->settings['event_id'] = false;
        $this->pluginSettingsFlush();
        return true;
    }
    
    private function mailingGetProcessingMessages(){
        $sql = "
            SELECT 
                *
            FROM
                plugin_message_list
            WHERE
                message_status = 'processing'
            ";
        return $this->get_list($sql);
    }

//    private function passiveCompanyGet($reciever){
//        $sql = "
//            SELECT
//                cl.*, ct.label as company_label
//            FROM
//                companies_list cl
//                    JOIN
//                companies_tree ct ON (cl.branch_id = ct.branch_id)
//            WHERE
//                company_email = '$reciever' OR company_mobile = '$reciever'
//            ";
//        return $this->get_row($sql);
//    }
}