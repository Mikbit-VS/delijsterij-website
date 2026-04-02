import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(__dirname, '..');
const srcDir = path.join(
  'C:',
  'Users',
  'mikbr',
  'Documents',
  'De Lijsterij',
  "Foto's Karolien Lijsterij",
  'Borduurwerk'
);
const destDir = path.join(root, 'site', 'assets', 'images', 'portfolio', 'delicate-werken');

const files = fs.readdirSync(srcDir).filter((f) => /\.jpe?g$/i.test(f)).sort();
fs.mkdirSync(destDir, { recursive: true });
files.forEach((f, i) => {
  const n = String(i + 1).padStart(2, '0');
  fs.copyFileSync(path.join(srcDir, f), path.join(destDir, `delicate-${n}.jpg`));
  console.log(f, '->', `delicate-${n}.jpg`);
});
