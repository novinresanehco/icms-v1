import React, { createContext, useContext, useReducer, useState } from 'react';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';

// Template Context
const TemplateContext = createContext(null);

const templateReducer = (state, action) => {
  switch (action.type) {
    case 'SET_LAYOUT':
      return { ...state, currentLayout: action.layout };
    case 'UPDATE_ZONE':
      return {
        ...state,
        content: { ...state.content, [action.zone]: action.content }
      };
    case 'SET_MEDIA':
      return { ...state, media: action.media };
    default:
      return state;
  }
};

// Main Template System Component
export default function TemplateSystem({ 
  initialLayout = 'default', 
  content = {}, 
  media = [] 
}) {
  const [state, dispatch] = useReducer(templateReducer, {
    currentLayout: initialLayout,
    content: content,
    media: media
  });

  return (
    <TemplateContext.Provider value={{ state, dispatch }}>
      <TemplateManager />
    </TemplateContext.Provider>
  );
}

// Template Manager Component
const TemplateManager = () => {
  const { state } = useContext(TemplateContext);
  const { currentLayout, content, media } = state;

  const layouts = {
    default: { grid: 'grid-cols-1', zones: ['main'] },
    twoColumn: { grid: 'grid-cols-1 md:grid-cols-12', zones: ['main:8', 'sidebar:4'] },
    full: { grid: 'grid-cols-1', zones: ['header', 'main', 'footer'] }
  };

  const layout = layouts[currentLayout] || layouts.default;

  return (
    <div className="template-system">
      <div className={`grid gap-6 ${layout.grid}`}>
        {layout.zones.map(zone => {
          const [name, span] = zone.split(':');
          return (
            <div key={name} className={`${span ? `md:col-span-${span}` : ''}`}>
              <ContentZone name={name} content={content[name]} />
            </div>
          );
        })}
      </div>
      {media?.length > 0 && <MediaGallery items={media} />}
    </div>
  );
};

// Content Zone Component
const ContentZone = ({ name, content }) => {
  if (!content) return null;

  return (
    <div className="prose max-w-none">
      {typeof content === 'string' ? (
        <div dangerouslySetInnerHTML={{ __html: content }} />
      ) : content}
    </div>
  );
};

// Media Gallery Component
const MediaGallery = ({ items = [] }) => {
  const [page, setPage] = useState(0);
  const [selectedItem, setSelectedItem] = useState(null);
  const itemsPerPage = 12;
  const pages = Math.ceil(items.length / itemsPerPage);
  const currentItems = items.slice(page * itemsPerPage, (page + 1) * itemsPerPage);

  return (
    <div className="space-y-6 mt-8">
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        {currentItems.map((item, idx) => (
          <div 
            key={idx} 
            className="aspect-square cursor-pointer group"
            onClick={() => setSelectedItem(item)}
          >
            <img
              src={item.url || '/api/placeholder/400/320'}
              alt={item.alt || ''}
              className="w-full h-full object-cover rounded-lg transition-transform group-hover:scale-105"
            />
          </div>
        ))}
      </div>

      {pages > 1 && (
        <div className="flex justify-center items-center gap-4">
          <button
            onClick={() => setPage(p => Math.max(0, p - 1))}
            disabled={page === 0}
            className="p-2 rounded-full hover:bg-gray-100 disabled:opacity-50"
          >
            <ChevronLeft className="w-5 h-5" />
          </button>
          <span className="text-sm">Page {page + 1} of {pages}</span>
          <button
            onClick={() => setPage(p => Math.min(pages - 1, p + 1))}
            disabled={page === pages - 1}
            className="p-2 rounded-full hover:bg-gray-100 disabled:opacity-50"
          >
            <ChevronRight className="w-5 h-5" />
          </button>
        </div>
      )}

      {selectedItem && (
        <div className="fixed inset-0 z-50 bg-black/90 flex items-center justify-center">
          <button
            onClick={() => setSelectedItem(null)}
            className="absolute top-4 right-4 text-white hover:text-gray-300"
          >
            <X className="w-6 h-6" />
          </button>
          <img
            src={selectedItem.url}
            alt={selectedItem.alt || ''}
            className="max-h-[90vh] max-w-[90vw] object-contain"
          />
        </div>
      )}
    </div>
  );
};
