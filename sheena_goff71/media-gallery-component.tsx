import React, { useState } from 'react';
import { Camera } from 'lucide-react';

export default function MediaGallery({ items, maxItems = 50 }) {
  const [currentPage, setCurrentPage] = useState(0);
  const itemsPerPage = 12;

  // Security: Limit maximum items
  const safeItems = items.slice(0, maxItems);
  const pageCount = Math.ceil(safeItems.length / itemsPerPage);
  const currentItems = safeItems.slice(
    currentPage * itemsPerPage,
    (currentPage + 1) * itemsPerPage
  );

  return (
    <div className="w-full space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        {currentItems.map((item) => (
          <div 
            key={item.id} 
            className="relative aspect-square bg-gray-100 rounded-lg overflow-hidden"
          >
            {item.type === 'image' ? (
              <img
                src={item.secureUrl}
                alt={item.alt}
                className="w-full h-full object-cover"
                loading="lazy"
              />
            ) : (
              <div className="flex items-center justify-center w-full h-full">
                <Camera size={48} className="text-gray-400" />
              </div>
            )}
            <div className="absolute inset-0 bg-black bg-opacity-0 hover:bg-opacity-10 transition-all duration-200" />
          </div>
        ))}
      </div>

      {pageCount > 1 && (
        <div className="flex justify-center gap-2">
          {Array.from({ length: pageCount }).map((_, index) => (
            <button
              key={index}
              onClick={() => setCurrentPage(index)}
              className={`w-3 h-3 rounded-full ${
                currentPage === index ? 'bg-blue-600' : 'bg-gray-300'
              }`}
            />
          ))}
        </div>
      )}
    </div>
  );
}
