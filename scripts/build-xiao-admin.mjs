import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync, chmodSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Script } from 'node:vm';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const variant = join(root, 'variants/xiao-admin');
const sha = value => createHash('sha256').update(value).digest('hex');

export function buildXiaoAdmin(output) {
  output = resolve(output);
  if (existsSync(output)) throw new Error(`Output already exists: ${output}. Choose an empty output directory.`);
  const patch = JSON.parse(readFileSync(join(variant, 'bundle.patch.json'), 'utf8'));
  const source = readFileSync(join(root, 'public/assets/admin/umi.js'), 'utf8');
  if (sha(source) !== patch.sourceSha256) throw new Error('The source admin bundle changed. Review and regenerate the Xiao compatibility patch first.');
  const lines = source.split(/(?<=\n)/);
  for (const edit of [...patch.edits].reverse()) {
    const oldLines = edit.oldText ? edit.oldText.split(/(?<=\n)/).filter((line, index, all) => line || index < all.length - 1) : [];
    if (lines.slice(edit.startLine, edit.startLine + oldLines.length).join('') !== edit.oldText) {
      throw new Error(`Compatibility patch mismatch at line ${edit.startLine + 1}`);
    }
    const newLines = edit.newText ? edit.newText.split(/(?<=\n)/).filter((line, index, all) => line || index < all.length - 1) : [];
    lines.splice(edit.startLine, oldLines.length, ...newLines);
  }
  const bundle = lines.join('');
  if (sha(bundle) !== patch.outputSha256) throw new Error('Xiao output checksum mismatch.');
  if (/server\/mx|serverMx|callMx|mxOnly|mundordp|\bmc1\b|Mundo X|MGate/.test(bundle)) {
    throw new Error('The Xiao bundle still contains a Mundo-only feature.');
  }
  new Script(bundle, { filename: 'xiao-admin/umi.js' });
  mkdirSync(output, { recursive: true });
  cpSync(join(root, 'public/assets/admin'), join(output, 'public/assets/admin'), { recursive: true });
  writeFileSync(join(output, 'public/assets/admin/umi.js'), bundle);
  mkdirSync(join(output, 'resources/views'), { recursive: true });
  cpSync(join(variant, 'admin.blade.php'), join(output, 'resources/views/admin.blade.php'));
  for (const file of ['install.sh', 'README.md', 'compatibility.json']) cpSync(join(variant, file), join(output, file));
  cpSync(join(root, 'LICENSE'), join(output, 'LICENSE'));
  chmodSync(join(output, 'install.sh'), 0o755);
  const files = [];
  function walk(directory) {
    for (const entry of readdirSync(directory, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
      const path = join(directory, entry.name);
      if (entry.isDirectory()) walk(path);
      else files.push(`${sha(readFileSync(path))}  ${relative(output, path)}`);
    }
  }
  walk(output);
  writeFileSync(join(output, 'SHA256SUMS'), files.join('\n') + '\n');
  return output;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  console.log(buildXiaoAdmin(process.argv[2] || join(root, 'test-results/xiao-admin')));
}
