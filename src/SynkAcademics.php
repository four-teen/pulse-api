<?php
declare(strict_types=1);

/** PULSE consumes an aggregates-only HTTPS API; no database connection in PULSE. */
final class PulseSynkAcademics
{
    private const MAX_BYTES = 2097152;
    public static function enabled(): bool { return filter_var(config('SINQ_ACADEMICS_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN); }
    public static function studentBasis(): string { return 'active_class_lists'; }
    public static function unavailable(string $status = 'unavailable'): array
    {
        $counts = ['total'=>null, 'byCampus'=>array_fill_keys(array_column(pulseCampusCatalogue(), 'id'), null)];
        return ['status'=>$status,'term'=>null,'retrievedAt'=>null,'studentBasis'=>self::studentBasis(), 'faculty'=>$counts,'students'=>$counts,
            'colleges'=>[],'programs'=>[],'quality'=>['facultyWithMultipleColleges'=>0,'studentNamesWithMultipleIds'=>0]];
    }
    private static function source(): array
    {
        $local = filter_var(config('SINQ_ACADEMICS_LOCAL_TEST_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
        return ['local'=>$local, 'base'=>$local ? config('SINQ_ACADEMICS_LOCAL_TEST_BASE_URL', 'http://127.0.0.1:8081') : (trim(config('SINQ_API_BASE_URL')) ?: 'https://sinq.sksu-orms.net'),
            'path'=>trim(config('SINQ_ACADEMICS_PATH', '/backend/pulse_api.php?resource=academic-counts')),
            'token'=>$local ? config('SINQ_ACADEMICS_LOCAL_TEST_TOKEN', config('SINQ_FACULTY_LOCAL_TEST_TOKEN')) : config('SINQ_API_TOKEN')];
    }
    public static function configurationIssues(): array
    {
        if (!self::enabled()) return ['Synk academic counts are disabled.'];
        $source=self::source(); $url=parse_url($source['base']);
        if ($source['local']) {
            if (config('APP_ENV') !== 'development' || !$url || ($url['scheme']??'') !== 'http' || ($url['host']??'') !== '127.0.0.1'
                || !isset($url['port']) || $url['port']<1 || $url['port']>65535 || isset($url['user']) || isset($url['pass'])
                || isset($url['query']) || isset($url['fragment']) || ($url['path']??'') !== '') return ['Loopback HTTP testing is available only in development.'];
        } elseif (!$url || ($url['scheme']??'') !== 'https' || strtolower($url['host']??'') !== strtolower(config('SINQ_API_ALLOWED_HOST','sinq.sksu-orms.net'))
            || !str_contains($url['host']??'', '.') || filter_var($url['host']??'', FILTER_VALIDATE_IP) || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment']) || !in_array($url['path']??'', ['', '/'], true) || isset($url['port']) && $url['port'] !== 443) return ['Configure the approved HTTPS Synk origin.'];
        $path=parse_url($source['path']); $query=[]; parse_str($path['query']??'', $query);
        if (!$path || !preg_match('#^/[A-Za-z0-9_./-]+$#D', $path['path']??'') || preg_match('#(?:^|/)\.\.(?:/|$)#', $path['path']??'')
            || isset($path['scheme']) || isset($path['host']) || isset($path['fragment']) || $query !== ['resource'=>'academic-counts']) return ['Configure the academic-counts API resource path.'];
        if (strlen($source['token']) < 32 || str_starts_with($source['token'], 'REPLACE_') || preg_match('/[\r\n\x00]/', $source['token'])) return ['Configure a private Synk API token of at least 32 characters.'];
        return [];
    }
    public static function read(): array
    {
        if (!self::enabled()) return self::unavailable('disabled');
        try { return self::configurationIssues() ? self::unavailable() : self::validate(self::fetch()); }
        catch (Throwable $error) { error_log('PULSE Synk academic API unavailable: '.get_class($error)); return self::unavailable(); }
    }
    private static function fetch(): array
    {
        if (!extension_loaded('curl')) throw new RuntimeException('PHP cURL is required.');
        $source=self::source(); $curl=curl_init(rtrim($source['base'],'/').$source['path']);
        if ($curl === false) throw new RuntimeException('Unable to initialize source request.');
        $body=''; $tooLarge=false;
        try {
            curl_setopt_array($curl,[CURLOPT_HTTPGET=>true, CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: Bearer '.$source['token'],'User-Agent: PULSE-API/1.0'],
                CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>$source['local'] ? CURLPROTO_HTTP : CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_WRITEFUNCTION=>static function ($handle,string $chunk) use (&$body,&$tooLarge): int {
                    if (strlen($body)+strlen($chunk)>self::MAX_BYTES) { $tooLarge=true; return 0; } $body.=$chunk; return strlen($chunk);
                }]);
            $success=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); $type=strtolower(curl_getinfo($curl,CURLINFO_CONTENT_TYPE)?:'');
            if ($success===false || $tooLarge || $status!==200 || !str_contains($type,'application/json')) throw new RuntimeException('The source did not return successful JSON.');
            $payload=json_decode($body,true,32,JSON_THROW_ON_ERROR);
            if (!is_array($payload)) throw new RuntimeException('Invalid academic API response.');
            return $payload;
        } finally { curl_close($curl); }
    }
    /** Reconstruct an allowlisted response so source fields never leak through the proxy. */
    public static function validate(array $data): array
    {
        if (($data['schemaVersion']??null)!==1 || ($data['sourceId']??'')!=='sinq' || ($data['status']??'')!=='connected' || ($data['studentBasis']??'')!==self::studentBasis()) throw new RuntimeException('Unsupported academic source contract.');
        $term=$data['term']??null;
        if (!is_array($term) || !is_int($term['academicYearId']??null) || $term['academicYearId']<1 || !is_string($term['academicYear']??null)
            || !preg_match('/^(\d{4})-(\d{4})$/D',$term['academicYear'],$years) || (int)$years[2] !== (int)$years[1]+1 || !in_array($term['semester']??null,[1,2,3],true)) throw new RuntimeException('Invalid academic term.');
        $label=[1=>'1st Semester',2=>'2nd Semester',3=>'Midyear'][$term['semester']];
        if (($term['key']??'')!==$term['academicYear'].':'.$term['semester'] || ($term['semesterLabel']??'')!==$label) throw new RuntimeException('Inconsistent academic term.');
        $term=array_intersect_key($term,array_flip(['academicYearId','academicYear','semester','semesterLabel','key']));
        $stamp=$data['retrievedAt']??null;
        if (!is_string($stamp) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$stamp)) throw new RuntimeException('Invalid retrieval time.');
        $date=new DateTimeImmutable($stamp); $errors=DateTimeImmutable::getLastErrors();
        if ($errors!==false && ($errors['warning_count'] || $errors['error_count']) || abs(time()-$date->getTimestamp())>300) throw new RuntimeException('The source must return fresh counts.');
        $ids=array_column(pulseCampusCatalogue(),'id');
        $output=['status'=>'connected','term'=>$term,'retrievedAt'=>$stamp,'studentBasis'=>self::studentBasis()];
        foreach (['faculty','students'] as $group) {
            $input=$data[$group]??[]; $total=self::count($input['total']??null); $byCampus=$input['byCampus']??null;
            if (!is_array($byCampus) || count($byCampus)!==count($ids) || array_diff(array_keys($byCampus),$ids)) throw new RuntimeException('Invalid campus counts.');
            $counts=[];
            foreach ($ids as $id) { $counts[$id]=self::count($byCampus[$id]??null); if ($counts[$id]>$total) throw new RuntimeException('Inconsistent campus counts.'); }
            if (array_sum($counts)<$total) throw new RuntimeException('Incomplete campus counts.');
            $output[$group]=['total'=>$total,'byCampus'=>$counts];
        }
        $colleges=[]; $programs=[];
        foreach (['colleges','programs'] as $list) if (!isset($data[$list]) || !is_array($data[$list]) || !array_is_list($data[$list]) || count($data[$list])>10000) throw new RuntimeException('Invalid unit list.');
        foreach ($data['colleges'] as $row) {
            $id=self::count($row['id']??null);
            if (!$id || isset($colleges[$id]) || !in_array($row['campusId']??'',$ids,true)) throw new RuntimeException('Invalid college scope.');
            $faculty=self::count($row['faculty']??null); $students=self::count($row['students']??null);
            if ($faculty>$output['faculty']['byCampus'][$row['campusId']] || $students>$output['students']['byCampus'][$row['campusId']]) throw new RuntimeException('Inconsistent college counts.');
            $colleges[$id]=['id'=>$id,'name'=>self::text($row['name']??null,true),'code'=>self::text($row['code']??null),'campusId'=>$row['campusId'],'faculty'=>$faculty,'students'=>$students];
        }
        foreach ($data['programs'] as $row) {
            $id=self::count($row['id']??null); $collegeId=self::count($row['collegeId']??null); $college=$colleges[$collegeId]??null;
            if (!$id || isset($programs[$id]) || !$college || ($row['campusId']??'')!==$college['campusId']) throw new RuntimeException('Invalid program scope.');
            $students=self::count($row['students']??null); if ($students>$college['students']) throw new RuntimeException('Inconsistent program counts.');
            $programs[$id]=['id'=>$id,'name'=>self::text($row['name']??null,true),'code'=>self::text($row['code']??null),'major'=>self::text($row['major']??null),'collegeId'=>$collegeId,'campusId'=>$row['campusId'],'students'=>$students];
        }
        foreach (['faculty','students'] as $group) foreach ($ids as $campus) if (array_sum(array_column(array_filter($colleges,static fn($row)=>$row['campusId']===$campus),$group))<$output[$group]['byCampus'][$campus]) throw new RuntimeException('Incomplete college counts.');
        foreach ($colleges as $college) if (array_sum(array_column(array_filter($programs,static fn($row)=>$row['collegeId']===$college['id']),'students'))<$college['students']) throw new RuntimeException('Incomplete program counts.');
        $output['colleges']=array_values($colleges); $output['programs']=array_values($programs);
        $output['quality']=['facultyWithMultipleColleges'=>self::count($data['quality']['facultyWithMultipleColleges']??null),'studentNamesWithMultipleIds'=>self::count($data['quality']['studentNamesWithMultipleIds']??null)];
        return $output;
    }
    private static function count(mixed $value): int
    {
        if (!is_int($value) || $value<0 || $value>1000000000) throw new RuntimeException('Invalid headcount.'); return $value;
    }
    private static function text(mixed $value,bool $required=false): string
    {
        if (!is_string($value) || strlen($value)>2000 || $required && trim($value)==='') throw new RuntimeException('Invalid unit label.'); return $value;
    }
}
