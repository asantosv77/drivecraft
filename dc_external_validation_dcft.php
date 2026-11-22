<?php
// Trusted validator, deliberately outside the AI edit allowlist.
function dcmtAdapterSkeleton(string $code,bool $validate=false):string {
 $tokens=token_get_all($code,TOKEN_PARSE);$text='';$names=[];$length=count($tokens);
 for($i=0;$i<$length;$i++){
  $token=$tokens[$i];$value=is_array($token)?$token[1]:$token;
  if(!is_array($token)||$token[0]!==T_FUNCTION){$text.=$value;continue;}
  $text.=$value;$name='';
  while(++$i<$length){$t=$tokens[$i];$v=is_array($t)?$t[1]:$t;if($name===''&&is_array($t)&&$t[0]===T_STRING)$name=$v;$text.=$v;if($v==='{')break;}
  if(!in_array($name,['dcmtAdapterSearch','dcmtAdapterInspect','dcmtAdapterFormat','dcmtAdapterAudioFormat'],true)||isset($names[$name]))throw new RuntimeException('Función fuera de la lista permitida');$names[$name]=true;
  $depth=1;$body=[];while(++$i<$length){$t=$tokens[$i];if($t==='{')$depth++;elseif($t==='}'){$depth--;if($depth===0)break;}$body[]=$t;}
  // Re-count reliably: braces in strings are array tokens, never delimiters.
  if($depth!==0)throw new RuntimeException('Cuerpo de función inválido');
  if($validate)dcmtPureBody($body);$text.='__BODY__}';
 }
 if(count($names)!==4)throw new RuntimeException('Se requieren las cuatro funciones originales');return $text;
}
function dcmtPureBody(array $tokens):void {
 $allowed=['is_array','is_string','is_numeric','preg_match','trim','mb_substr','substr','count','min','max','strtolower','strtoupper','strlen','mb_strlen','array_slice','array_values','array_unique','array_key_exists','str_contains','str_starts_with','str_ends_with','intval','floatval','boolval','in_array','explode','implode','preg_replace'];
 $forbidden=[T_NEW,T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR,T_DOUBLE_COLON,T_INCLUDE,T_INCLUDE_ONCE,T_REQUIRE,T_REQUIRE_ONCE,T_EVAL,T_EXIT,T_GLOBAL,T_STATIC,T_GOTO,T_HALT_COMPILER,T_YIELD,T_YIELD_FROM,T_FUNCTION,T_FN,T_CLASS,T_INTERFACE,T_TRAIT,T_OPEN_TAG,T_OPEN_TAG_WITH_ECHO,T_CLOSE_TAG,T_INLINE_HTML,T_DIR,T_FILE,T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES,T_STRING_VARNAME,T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED,T_NAME_RELATIVE,T_NS_SEPARATOR,T_ECHO,T_PRINT,T_DECLARE];
 foreach($tokens as $i=>$t){
  if(!is_array($t)){if(in_array($t,['`','$'],true))throw new RuntimeException('Expresión dinámica no permitida');if(in_array($t,[']',')'],true)){for($k=$i+1;$k<count($tokens);$k++){if(is_array($tokens[$k])&&in_array($tokens[$k][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))continue;if($tokens[$k]==='(')throw new RuntimeException('Llamada dinámica no permitida');break;}}continue;}
  if(in_array($t[0],$forbidden,true))throw new RuntimeException('El adaptador solo puede contener transformaciones puras');
  if($t[0]===T_VARIABLE&&(str_starts_with($t[1],'$_')||in_array($t[1],['$GLOBALS','$this'],true)))throw new RuntimeException('Acceso a estado protegido');
  $next=null;for($j=$i+1;$j<count($tokens);$j++){if(is_array($tokens[$j])&&in_array($tokens[$j][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))continue;$next=$tokens[$j];break;}
  if($next==='('&&($t[0]===T_VARIABLE||$t[0]===T_CONSTANT_ENCAPSED_STRING||($t[0]===T_STRING&&!in_array(strtolower($t[1]),$allowed,true))))throw new RuntimeException('Llamada no permitida en adaptador');
 }
}
function dcmtValidateAdapter(string $old,string $candidate):void {
 if(strlen($candidate)>30000||!str_starts_with($candidate,'<?php'))throw new RuntimeException('Archivo de adaptador inválido');
 if(dcmtAdapterSkeleton($old)!==dcmtAdapterSkeleton($candidate,true))throw new RuntimeException('Cambió una firma o código protegido fuera de los cuerpos de función');
}
