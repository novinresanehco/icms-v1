import React, { createContext, useContext, useReducer } from 'react';

const TemplateContext = createContext(null);
const TemplateDispatchContext = createContext(null);

const templateReducer = (state, action) => {
  switch (action.type) {
    case 'SET_LAYOUT':
      return { ...state, currentLayout: action.layout };
    case 'UPDATE_ZONE':
      return {
        ...state,
        content: {
          ...state.content,
          [action.zone]: action.content
        }
      };
    case 'SET_MEDIA':
      return { ...state, media: action.media };
    default:
      return state;
  }
};

export function TemplateProvider({ children }) {
  const [state, dispatch] = useReducer(templateReducer, {
    currentLayout: 'default',
    content: {},
    media: []
  });

  return (
    <TemplateContext.Provider value={state}>
      <TemplateDispatchContext.Provider value={dispatch}>
        {children}
      </TemplateDispatchContext.Provider>
    </TemplateContext.Provider>
  );
}

export function useTemplate() {
  return useContext(TemplateContext);
}

export function useTemplateDispatch() {
  return useContext(TemplateDispatchContext);
}
