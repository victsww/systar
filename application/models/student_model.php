<?php
require_once APPPATH.'/models/people_model.php';
class Student_model extends People_model{
	
	var $profile=array(
		'youth_league'=>'团员',
		'junior_school'=>'初中',
		'source_type'=>'生源类型',
		'resident'=>'住校',
		'dormitory'=>'宿舍',
		'mobile'=>'手机',
		'email'=>'电子邮件',
		'phone'=>'家庭电话',
		'address'=>'家庭地址',
		'community'=>'居委会',
		'bank_account'=>'银行帐号',
		'diseases_history'=>'疾病史'
	);
	
	function __construct(){
		parent::__construct();
	}
	
	/**
	 * 获取一个学生的信息，包括people表和people_profile表中的信息
	 * @param int $id
	 * @return array $student_info
	 */
	function fetch($id){
		$id=intval($id);
		
		$people_info=parent::fetch($id);
		
		$query="SELECT name,content FROM people_profile WHERE people=$id";
		
		//获得此人的全部资料项
		//尽管people_profile采取保存所有历史更改不删除的方式，但转化成数组时此人的旧信息项会被最新的替换
		$profile_all=array_sub($this->db->query($query)->result_array(),'content','name');

		$student_profile=array_intersect_key($profile_all, $this->profile);
		
		$student_info=$people_info+$student_profile;
		
		return $student_info;
	}

	function updateClass($people,$team,$id_in_team,$term){
		$team_people=array();
		
		isset($team) && $team_people['team']=$team;
		isset($id_in_team) && $team_people['id_in_team']=$id_in_team;
		
		$team_people && $this->db->update('team_people',$team_people,array('people'=>$people,'term'=>$term,'relation'=>'就读'));

		if($this->db->affected_rows()==0){
			$relation='就读';
			$this->db->insert('team_people',compact('people','team','id_in_team','term','relation'));
		}
	}
	
	function update($student,$data){
		
		if(is_null($data)){
			return true;
		}
		
		$student_profile=array();
		
		if($data){//排除$data为NULL的情况
			$student_profile=array_intersect_key($data, $this->profile);
		}
		
		$student_profile_batch_data=array();
		foreach($student_profile as $profile_field => $profile_value){
			$student_profile_batch_data[]=array('name'=>$profile_field,'content'=>$profile_value,'people'=>$this->id);
			$student_profile_batch_data[count($student_profile_batch_data)-1]+=uidTime(false);
		}
		
		parent::update($student,$data);
		
		//$this->db->where(array('people'=>$this->id))->update_batch('people_profile', $student_profile_batch_data, 'name');
		
		if($student_profile_batch_data){
			$this->db->insert_batch('people_profile', $student_profile_batch_data);
		}
		
		return true;
	}
	
	function getList($config=array()){
		return parent::getList($config);
	}
	
	/**
	 * 获得一个学生的家庭成员列表
	 */
	function getRelativeList($student_id){
		$student_id=intval($student_id);
		
		$query="
			SELECT 
				relatives.id,relatives.name,people_relationship.relation,relatives.work_for,relatives_contact.content AS contact
			FROM 
				people AS relatives
				INNER JOIN (
					SELECT people,content FROM people_profile WHERE name='mobile'
				)relatives_contact ON relatives_contact.people=relatives.id
				INNER JOIN people_relationship ON people_relationship.relative=relatives.id 
					AND people_relationship.relation_type='家庭成员'
			WHERE people_relationship.people=$student_id
		";
		
		return $this->db->query($query)->result_array();
	}
	
	/**
	 * 获得一个学生的奖惩记录列表
	 */
	function getBehaviourList($student_id,$limit=5){
		$student_id=intval($student_id);
		$limit=intval($limit);

		$query="
			SELECT name,type,date,level,content FROM student_behaviour WHERE student = $student_id
			LIMIT $limit
		";
		
		return $this->db->query($query)->result_array();
	}
	
	function getCommentList($student_id,$limit=5){
		$student_id=intval($student_id);
		$limit=intval($limit);

		$query="
			SELECT student_comment.title,student_comment.content,
				FROM_UNIXTIME(student_comment.time,'%Y-%m-%d') AS time,IF(staff.name IS NULL,student_comment.username,staff.name) AS username 
			FROM student_comment LEFT JOIN people AS staff ON staff.id=student_comment.uid 
			WHERE student = $student_id AND (student_comment.reply_to IS NULL OR student_comment.reply_to = {$this->user->id} OR student_comment.uid = {$this->user->id})
			ORDER BY student_comment.time DESC
			LIMIT $limit
		";
		
		return $this->db->query($query)->result_array();
	}
	
	/**
	 * 家校互动页面学生评价留言列表
	 * TODO跟上面的getCommentList合并兼容
	 */
	function getInteractiveList(){
		$student_id=intval($student_id);

		$query="
			SELECT student_comment.title,student_comment.content,
				FROM_UNIXTIME(student_comment.time,'%Y-%m-%d') AS date,student_comment.username,student_comment.student,
				view_student.name AS student_name
			FROM student_comment INNER JOIN view_student ON student_comment.student=view_student.id
			WHERE student_comment.reply_to='{$this->user->id}' 
				OR student_comment.uid='{$this->user->id}' 
				OR (
					'".isset($_SESSION['manage_class'])."' 
					AND view_student.class='{$_SESSION['manage_class']['id']}'
				)
			ORDER BY time DESC
		";
		
		$query=$this->pagination($query);
		
		return $this->db->query($query)->result_array();
	}
	
	function updateView($student_id=NULL){
		$this->db->query("DROP TABLE IF EXISTS view_student");
		$this->db->query("
			CREATE TABLE view_student
			SELECT 
				student.id AS id,student.gender,student.name AS name,student.type AS type,student.id_card AS id_card,student.extra_course,
				right((1000000 + concat(student_class.class,right((100 + student_class.num_in_class),2))),6) AS num,
				class.id AS class,class.name AS class_name,class.depart AS depart,
				grade.id AS grade,grade.name AS grade_name 
			FROM 
				student 
				INNER JOIN student_class ON student.id = student_class.student
				INNER JOIN class ON student_class.class = class.id
				INNER JOIN grade ON grade.id = class.grade
			WHERE
				student_class.term = '".$this->school->current_term."'
			ORDER BY num
		");
		$this->db->query("ALTER TABLE  `view_student` ADD PRIMARY KEY (  `id` )");
		$this->db->query("ALTER TABLE  `view_student` ADD INDEX (type)");
		$this->db->query("ALTER TABLE  `view_student` ADD INDEX (num)");
		$this->db->query("ALTER TABLE  `view_student` ADD INDEX (class)");
		$this->db->query("ALTER TABLE  `view_student` ADD INDEX (grade)");
		$this->db->query("ALTER TABLE  `view_student` ADD INDEX (depart)");
		$this->db->query("ALTER TABLE  `view_student` ADD INDEX (extra_course)");
		$this->db->query("ALTER TABLE  `view_student` ADD FOREIGN KEY (  `id` ) REFERENCES  `starsys`.`student` (`id`) ON DELETE NO ACTION ON UPDATE CASCADE");
	}
	
	function changeClass($student_id,$old_class_id,$new_class_id){
		if($old_class_id!=$new_class_id){
			$new_num_in_class=db_fetch_field("SELECT MAX(num_in_class)+1 FROM student_class WHERE class='".$new_class_id."' AND term='".$this->school->current_term."'");
			
			$this->db->update('student_class',array('num_in_class'=>$new_num_in_class,'class'=>$new_class_id),"student = '".$student_id."' AND class = '".$old_class_id."' AND term = '".$this->school->current_term."'");
			$new_student_num=$new_class_id.substr($new_num_in_class+100,-2);
			
			student_update($student_id);
			
			return $new_student_num;
	
		}else{
			return false;
		}
	}
	
	function addRelatives($student,$data){
		$relative_id=$this->add($data);
		
		$this->addProfile($relative_id,'mobile',$data['contact']);
		
		if($people_relationship_id=$this->addRelationship($student, $relative_id, $data['relationship'])){
			unset($_SESSION[CONTROLLER]['post'][$this->id]['student_relatives']);
			return $people_relationship_id;
		}else{
			return false;
		}
	}
	
	function addBehaviour($student,$data){
		$fields=array('name','date','type','level','content');
		
		$behaviour=array('student'=>$student);
		
		foreach($fields as $field){
			if(isset($data[$field])){
				$behaviour[$field]=$data[$field];
			}
		}

		$behaviour+=uidTime();
		
		if($this->db->insert('student_behaviour',$behaviour)){
			unset($_SESSION[CONTROLLER]['post'][$this->id]['student_behaviour']);
			return $this->db->insert_id();
		}else{
			return false;
		}
	}
	
	function addComment($student,$data){
		$fields=array('title','content','reply_to');
		
		$comment=array('student',$student);
		
		foreach($fields as $field){
			if(isset($data[$field])){
				$comment[$field]=$data[$field];
			}
		}

		$comment+=uidTime();
		
		if($this->db->insert('student_comment',$comment)){
			unset($_SESSION[CONTROLLER]['post'][$this->id]['student_comment']);
			return $this->db->insert_id();
		}else{
			return false;
		}
	}
	
	function deleteRelatives($student_relatives){
		//@TODO
	}
	
	function getScores($student){
		$student=intval($student);

		$query="SELECT exam_name,course_1,course_2,course_3,course_4,course_5,course_6,course_7,course_8,course_9,course_10,course_sum_3,course_sum_5,course_sum_8,rank_1,rank_2,rank_3,rank_4,rank_5,rank_6,rank_7,rank_8,rank_9,rank_10,rank_sum_3,rank_sum_5,rank_sum_8
			FROM view_score WHERE student = '".$student."'
		ORDER BY exam DESC";
	
		return $this->db->query($query)->result_array();
	}
	
	function testClassDiv($div,$data,$classes,$gender,$showResult=false){
		global $tests,$students,$subjects;
	
		$tests++;
		
		$score=array();
		/*$score:array(
			1(性别)=>array(
				1(班号)=>array(
					1(科目号)=>array(
						学号=>本科分数
					)
				)
			)
		)
		*/
	
		//将div分班方案分解为score分数表
		for($subject=0;$subject<$subjects;$subject++){
			foreach($div as $gender_in_array1 => $array1){
				foreach($array1 as $class=>$array2){
					foreach($array2 as $student){
						$score[$gender_in_array1][$class][$subject][$student]=$data[$student][$subject];
					}
				}
			}
		}
		
		//$_SESSION['score']=$score;
		//print_r($score);
		
		$result=array();
	
		for($subject=0;$subject<$subjects;$subject++){
			for($class=0;$class<$classes;$class++){
				$result[$class][$subject]['num']=count($score[$gender][$class][$subject]);//得到每班每学科的人数
				$result[$class][$subject]['sum']=array_sum($score[$gender][$class][$subject]);//得到每班每学科的和
				$result[$class][$subject]['aver']=$result[$class][$subject]['sum']/$result[$class][$subject]['num'];//得到每班每学科的平均值
				//$result[$class][$subject]['std']=std($score[$gender][$class][$subject],$result[$class][$subject]['aver']);//得到每班每学科的标准差
			}
		}
		
		if($showResult){
			echo "\n<br>result".$gender.": "; print_r($result);
		}
		
		/*for($subject=0;$subject<$subjects;$subject++){
			for($class=0;$class<$classes;$class++){
		
				$std[]=$result[$class][$subject]['std'];
		
			}
		}
		
		$std_sum=array_sum($std);//各班各学科的标准差的和*/
		
		$aver_std=array();
	
		for($subject=0;$subject<$subjects;$subject++){
	
			$aver=array();
	
			for($class=0;$class<$classes;$class++){
				$aver[]=$result[$class][$subject]['aver'];
			}
			$aver_std[]=std($aver);
		}
		
		$aver_std_sum=array_sum($aver_std);//各班每学科总分的标差的和
		
		return $aver_std_sum;
	}
	
	function getIdByParentUid($parent_uid){
		return db_fetch_field("SELECT id FROM student WHERE parent = '".$parent_uid."'");
	}
}