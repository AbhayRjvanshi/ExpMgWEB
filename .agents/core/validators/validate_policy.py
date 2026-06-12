import sys, re
from pathlib import Path
def v_f(sf, bl):
 try:
  c = Path(sf).read_text()
  m = re.search('## POLICY(.*?)(## |$)', c, re.S)
  if not m: return 0
  p = m.group(1)
  vs = 0
  for t in bl:
   if not t or t.startswith('#'): continue
   if re.search(chr(92) + 'b' + re.escape(t) + chr(92) + 'b', p):
    vs += 1
  return vs
 except: return 0
if len(sys.argv) < 3: sys.exit(1)
sd = Path(sys.argv[1]); bf = Path(sys.argv[2])
bl = bf.read_text().splitlines()
t = 0
for f in sd.rglob('SKILL.md'): t += v_f(f, bl)
sys.exit(0 if t == 0 else 1)