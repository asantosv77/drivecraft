/* Visor Markdown seguro, sin CDN. Solo HTML creado a partir de texto escapado. */
(function(){
 'use strict';
 const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 function inline(src){
   let text=String(src), out='',pos=0;
   const pat=/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)|`([^`\n]+)`|\*\*([^*\n]+)\*\*|\*([^*\n]+)\*\*/g;
   let m;
   while((m=pat.exec(text))){
     out+=esc(text.slice(pos,m.index));
     if(m[1])out+='<a href="'+esc(m[2])+'" target="_blank" rel="noopener noreferrer">'+esc(m[1])+'</a>';
     else if(m[3])out+='<code>'+esc(m[3])+'</code>';
     else if(m[4])out+='<strong>'+esc(m[4])+'</strong>';
     else out+='<em>'+esc(m[5])+'</em>';
     pos=pat.lastIndex;
   }
   return out+esc(text.slice(pos));
 }
 function render(src){
   const lines=String(src).replace(/\r\n?/g,'\n').split('\n');let html='',inCode=false,code=[],list=null;
   const endList=()=>{if(list){html+='</'+list+'>';list=null;}};
   const flushCode=()=>{html+='<pre><code>'+esc(code.join('\n'))+'</code></pre>';code=[];};
   for(let i=0;i<lines.length;i++){
     const t=lines[i];
     if(/^\s*```/.test(t)){endList();if(inCode)flushCode();inCode=!inCode;continue;}
     if(inCode){code.push(t);continue;}
     if(!t.trim()){endList();continue;}
     let m=t.match(/^\s{0,3}(#{1,6})\s+(.+)$/);
     if(m){endList();let n=m[1].length;html+='<h'+n+'>'+inline(m[2])+'</h'+n+'>';continue;}
     if(/^\s{0,3}([-*_]\s*){3,}$/.test(t)){endList();html+='<hr>';continue;}
     m=t.match(/^\s*>\s?(.*)$/);
     if(m){endList();html+='<blockquote>'+inline(m[1])+'</blockquote>';continue;}
     const delim=lines[i+1]||'';
     if(t.includes('|')&&/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/.test(delim)){
       endList();const cells=s=>s.trim().replace(/^\||\|$/g,'').split('|').map(x=>x.trim());
       const heads=cells(t);html+='<div class="dc-md-table-wrap"><table><thead><tr>'+heads.map(x=>'<th>'+inline(x)+'</th>').join('')+'</tr></thead><tbody>';i++;
       while(i+1<lines.length&&lines[i+1].includes('|')&&lines[i+1].trim()){
         i++;const vals=cells(lines[i]);html+='<tr>'+heads.map((_,j)=>'<td>'+inline(vals[j]||'')+'</td>').join('')+'</tr>';
       }
       html+='</tbody></table></div>';continue;
     }
     m=t.match(/^\s*([-*+]\s+|\d+\.\s+)(.*)$/);
     if(m){let kind=/\d/.test(m[1][0])?'ol':'ul';if(list!==kind){endList();html+='<'+kind+'>';list=kind;}html+='<li>'+inline(m[2])+'</li>';continue;}
     endList();html+='<p>'+inline(t)+'</p>';
   }
   endList();if(inCode)flushCode();
   return html;
 }
 window.dcMarkdown49Render=render;
})();
