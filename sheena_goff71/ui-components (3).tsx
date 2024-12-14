import React, { useState } from 'react';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';

// Core Display Component
const DisplaySystem = ({ content, media, template = 'default' }) => {
  const [activeMedia, setActiveMedia] = useState(null);

  return (
    <div className="w-full">
      <TemplateRenderer 
        content={content} 
        template={template} 
      />
      <MediaDisplay 
        items={media}
        onSelect={setActiveMedia}
      />
      {activeMedia && (
        <MediaViewer 
          item={activeMedia} 
          onClose={() => setActiveMedia(null)} 
        />
      )}
    </div>
  );
};

// Template Renderer
const TemplateRenderer = ({ content, template }) => {
  const layouts = {
    default: 'max-w-4xl mx-auto space-y-8',
    wide: 'max-w-6xl mx-auto space-y-8',
    full: 'w-full space-y-8'
  };

  return (
    <div className={layouts[template]}>
      {content.header && (
        <header className="prose max-w-none">
          {content.header}
        </header>
      )}
      <main className="grid md:grid-cols-12 gap-8">
        <div className="md:col-span-8">
          {content.main}
        </div>
        {content.sidebar && (
          <aside className="md:col-span-4">
            {content.sidebar}
          </aside>
        )}
      </main>
      {content.footer && (
        <footer className="prose max-w-none">
          {content.footer}
        </footer>
      )}
    </div>
  );
};

// Media Display
const MediaDisplay = ({ items = [], onSelect }) => {
  const [page, setPage] = useState(0);
  const perPage = 12;
  const pages = Math.ceil(items.length / perPage);
  const currentItems = items.slice(page * perPage, (page + 1) * perPage);

  return (
    <div className="space-y-6 mt-8">
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        {currentItems.map((item, idx) => (
          <div 
            key={item.id || idx} 
            className="aspect-square relative group cursor-pointer"
            onClick={() => onSelect(item)}
          >
            <img
              src={item.url || '/api/placeholder/400/320'}
              alt={item.alt || ''}
              className="w-full h-full object-cover rounded-lg"
            />
            <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity rounded-lg" />
          </div>
        ))}
      </div>
      
      {pages > 1 && (
        <nav className="flex items-center justify-center gap-4">
          <button
            onClick={() => setPage(p => Math.max(0, p - 1))}
            disabled={page === 0}
            className="p-2 disabled:opacity-50"
          >
            <ChevronLeft className="w-5 h-5" />
          </button>
          <span className="text-sm">
            Page {page + 1} of {pages}
          </span>
          <button
            onClick={() => setPage(p => Math.min(pages - 1, p + 1))}
            disabled={page === pages - 1}
            className="p-2 disabled:opacity-50"
          >
            <ChevronRight className="w-5 h-5" />
          </button>
        </nav>
      )}
    </div>
  );
};

// Media Viewer
const MediaViewer = ({ item, onClose }) => (
  <div className="fixed inset-0 z-50 bg-black/90 flex items-center justify-center">
    <button
      onClick={onClose}
      className="absolute top-4 right-4 text-white hover:text-gray-300"
    >
      <X className="w-6 h-6" />
    </button>
    <img
      src={item.url}
      alt={item.alt || ''}
      className="max-h-[90vh] max-w-[90vw] object-contain"
    />
  </div>
);

export default DisplaySystem;
