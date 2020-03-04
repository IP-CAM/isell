<?php
require_once 'Catalog.php';
class Pref extends Catalog {
    public $min_level=1;
    public $getStaffList=[];
    public function getStaffList() {
        $sql = "(SELECT 
                    user_id,
                    user_position,
                    first_name,
                    middle_name,
                    last_name,
                    id_type,
                    id_serial,
                    id_number,
                    id_given_by,
                    id_date,
                    CONCAT(last_name,' ',first_name,' ',middle_name) AS full_name,
                    CONCAT(last_name,' ',first_name,' ',middle_name) AS label 
                FROM 
		    " . BAY_DB_MAIN . ".user_list
                WHERE 
		    user_is_staff AND first_name IS NOT NULL AND last_name IS NOT NULL)
                UNION
                (SELECT
                    0,
                    '-',
                    '-',
                    '-',
                    '-',
                    '-',
                    '-',
                    '-',
                    '-',
                    '-',
                    '-',
                    '-'
                )";
        return $this->get_list($sql);
    }
    public $getPrefs=['[a-zA-Z0-9_\-,]+'];
    public function getPrefs($pref_names="") {// "pref1,pref2,pref3"
	$active_company_id=$this->Hub->acomp('company_id');
	if( $pref_names ){
	    $where = "WHERE active_company_id='$active_company_id' AND (pref_name='" . str_replace(',', "' OR pref_name='", $pref_names) . "')"; 
	} else {
	    $where = "WHERE active_company_id='$active_company_id'";
	}
        $this->query("SET SESSION group_concat_max_len = 1000000;");
        $prefs = $this->get_row("SELECT GROUP_CONCAT(pref_value SEPARATOR '~|~') pvals,GROUP_CONCAT(pref_name SEPARATOR '~|~') pnames FROM pref_list  $where");
        return (object) array_combine(explode('~|~', $prefs->pnames), explode('~|~', $prefs->pvals));
    }
    public $setPrefs=['[a-zA-Z0-9_\-]+','[^|]+'];
    public function setPrefs($field,$value='') {
	$active_company_id=$this->Hub->acomp('company_id');
	$this->Hub->set_level(2);
	if( !$field ){
	    return false;
	}
	$this->query("REPLACE pref_list SET pref_name='$field',pref_value='$value',active_company_id='$active_company_id'");
	return $this->db->affected_rows()>0?1:0;
    }
}