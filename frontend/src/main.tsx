import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import '@fontsource/plus-jakarta-sans/latin-500.css';
import '@fontsource/plus-jakarta-sans/latin-600.css';
import '@fontsource/plus-jakarta-sans/latin-700.css';
import '@fontsource/ibm-plex-sans/latin-400.css';
import '@fontsource/ibm-plex-sans/latin-500.css';
import '@fontsource/ibm-plex-sans/latin-600.css';
import '@fontsource/jetbrains-mono/latin-400.css';
import '@fontsource/jetbrains-mono/latin-500.css';
import 'material-symbols/rounded.css';
import { AuthApp } from './views/AuthApp';
import './styles.css';
import './tokens.css';

void document.fonts.load('20px "Material Symbols Rounded"').then(() => {
  if (document.fonts.check('20px "Material Symbols Rounded"')) {
    document.documentElement.dataset.materialSymbolsReady = 'true';
  }
}).catch((error: unknown) => {
  console.error('Material Symbols could not be loaded; using accessible icon fallbacks.', error);
});

const systemColorScheme = window.matchMedia('(prefers-color-scheme: dark)');
systemColorScheme.addEventListener('change', (event) => {
  const root = document.documentElement;
  if (root.dataset.themeChoice === 'system') {
    root.dataset.theme = event.matches ? 'dark' : 'light';
  }
});

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <BrowserRouter basename={(() => {
      const rawBasePath = import.meta.env.VITE_BASE_PATH?.trim() || '/';
      if (rawBasePath === '/') return undefined;
      return `/${rawBasePath.replace(/^\/+|\/+$/g, '')}`;
    })()}>
      <AuthApp />
    </BrowserRouter>
  </React.StrictMode>,
);
