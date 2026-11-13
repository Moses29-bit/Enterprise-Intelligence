import React from 'react';
import ReactDOM from 'react-dom/client';
import interLatin from '@fontsource-variable/inter/files/inter-latin-opsz-normal.woff2?url';
import { AuthApp } from './views/AuthApp';
import './styles.css';
import './tokens.css';

const fontPreload = document.createElement('link');
fontPreload.rel = 'preload';
fontPreload.href = interLatin;
fontPreload.as = 'font';
fontPreload.type = 'font/woff2';
fontPreload.crossOrigin = 'anonymous';
document.head.append(fontPreload);

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <AuthApp />
  </React.StrictMode>,
);
