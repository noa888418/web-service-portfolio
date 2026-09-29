import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { App } from './App'
import { api } from './api'
import { Session } from './session'
import './style.css'

const session = new Session(api, (path, replace) => {
  if (replace) window.history.replaceState(null, '', path)
  else window.history.pushState(null, '', path)
})
createRoot(document.getElementById('root')!).render(<StrictMode><App session={session} /></StrictMode>)
