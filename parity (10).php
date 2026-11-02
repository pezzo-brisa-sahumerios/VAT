<?php
require '/home/ubuntu/VAT-Detector/config/config.php';
require '/home/ubuntu/VAT-Detector/src/MotorForense.php';
$M = CargadorModelo::obtener(VAT_RUTA_MODELO);
$P = json_decode(file_get_contents($argv[1] ?? 'parity.json'), true);
$maxp=0;$maxd=0;$bad=0;
foreach ($P as $i=>$s) { $t=Tokenizador::normalizar($s['text']); $r=ClasificadorVAT::puntuar($t,$M); $p=ClasificadorVAT::calibrar($r['z'],$M['platt']);
  $dp=abs($p-$s['p']); $maxp=max($maxp,$dp); if($dp>1e-6){$bad++;}
  foreach($M['densos']['nombres'] as $j=>$n){$d=abs($r['dens']['valores'][$n]-$s['d'][$j]); if($d>1e-9){ if($d>$maxd) echo "dense $i $n {$r['dens']['valores'][$n]} {$s['d'][$j]}\n"; $maxd=max($maxd,$d);}}
}
echo "max|dp| $maxp maxdense $maxd bad $bad/".count($P)."\n";
$m=new MotorForense(VAT_RUTA_MODELO); $t0=microtime(true); $a=$m->analizar($P[0]['text']); echo json_encode($a['veredicto'],JSON_UNESCAPED_UNICODE)."\n".$a['prompt_estimado']['texto']."\n".round((microtime(true)-$t0)*1000)."ms\n";
file_put_contents('php_out.json', json_encode(array_map(fn($s)=>$m->analizar($s['text']), array_slice($P,0,40)), JSON_UNESCAPED_UNICODE));
